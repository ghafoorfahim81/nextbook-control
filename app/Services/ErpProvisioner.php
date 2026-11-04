<?php

namespace App\Services;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Models\Instance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks the NextBook to create the company of one customer, with its first
 * administrator, and ties the two together.
 *
 * The request is signed with the same private key that signs licences, so the
 * ERP, which holds only the public key, can tell it came from here and nobody
 * else can forge one. What is signed is "provision.v1", the timestamp, a
 * one-time nonce and the exact body; the ERP's VerifyControlSignature builds
 * the same string.
 *
 * The administrator's password never passes through here: the ERP makes the
 * account with a password nobody knows, and the person chooses their own with
 * "forgot password" on NextBook's sign-in page.
 *
 * Asking again for a customer whose company exists changes nothing there (the
 * ERP answers with the same company), so a lost reply can simply be retried.
 */
class ErpProvisioner
{
    /** What the customer's own record must hold before a company can be made from it. */
    private const REQUIRED = ['name_en', 'business_type', 'base_currency', 'calendar_type', 'locale'];

    /** @return array<int, string> the names of the customer's fields that are still empty */
    public function missing(Customer $customer): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $field) => blank($customer->{$field})));
    }

    /** Where the request would go: the customer's own address if it has one, else the shared NextBook. */
    public function target(Customer $customer): ?string
    {
        $url = $customer->instance?->base_url ?: config('control.erp.url');

        return $url ? rtrim((string) $url, '/') : null;
    }

    /**
     * @return array{company_id: string, created: bool, license_ok: bool, license_message: string}
     *
     * @throws ControlException with a message fit to show the member of staff
     */
    public function provision(Customer $customer, string $adminName, string $adminEmail): array
    {
        $instance = $customer->instance()->firstOrCreate([]);
        if ($instance->erp_company_id) {
            throw new ControlException('This customer already has a company in NextBook.');
        }

        if ($missing = $this->missing($customer)) {
            throw new ControlException('The customer record is incomplete: '.implode(', ', $missing).'.');
        }

        $url = $this->target($customer);
        if (! $url) {
            throw new ControlException('ERP_URL is not set, and this customer has no NextBook address of its own.');
        }
        if (app()->environment('production') && ! str_starts_with(strtolower($url), 'https://')) {
            throw new ControlException('The NextBook address must start with https:// in production: the access token would cross the network in the clear.');
        }

        // The token has to be in place before the ERP asks for its licence, which it does at once.
        $token = $instance->issueToken();

        $body = json_encode($this->body($customer, $token, $adminName, $adminEmail), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = Http::withHeaders($this->signedHeaders($body))
                ->withBody($body, 'application/json')->acceptJson()->withoutRedirecting()
                ->timeout((int) config('control.erp.timeout'))
                ->post($url.'/api/control/provision');
        } catch (ConnectionException) {
            throw new ControlException('NextBook could not be reached. Nothing was changed here; try again.');
        }

        if (! $response->successful()) {
            throw new ControlException($this->explain($response->status(), $response->json('errors') ?? [], (string) $response->json('message')));
        }

        $companyId = (string) $response->json('company_id');
        if (strlen($companyId) !== 26) {
            throw new ControlException('NextBook answered, but not with a company id.');
        }

        $instance->forceFill(['erp_company_id' => $companyId, 'base_url' => $instance->base_url ?: $url])->save();

        return [
            'company_id' => $companyId,
            'created' => (bool) $response->json('created'),
            'license_ok' => (bool) $response->json('license_ok'),
            'license_message' => (string) $response->json('license_message'),
        ];
    }

    /** @return array<string, mixed> */
    private function body(Customer $customer, string $token, string $adminName, string $adminEmail): array
    {
        $contact = $customer->contacts()->orderByDesc('is_primary')->first();

        $company = array_filter([
            'name_en' => $customer->name_en,
            'name_fa' => $customer->name_fa,
            'name_pa' => $customer->name_ps,
            'city' => $customer->city,
            'country' => 'Afghanistan',
            'phone' => $contact?->phone ? mb_substr((string) $contact->phone, 0, 20) : null,
            'calendar_type' => $customer->calendar_type,
            'business_type' => $customer->business_type,
            'locale' => $customer->locale,
            'base_currency' => $customer->base_currency,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            'customer_id' => $customer->id,
            'token' => $token,
            'company' => $company,
            'admin' => ['name' => $adminName, 'email' => $adminEmail],
        ];
    }

    /** @return array<string, string> */
    private function signedHeaders(string $body): array
    {
        $secret = base64_decode((string) config('control.license.private_key'), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ControlException('The signing key is missing: run php artisan control:keys.');
        }

        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(16));
        $signature = sodium_crypto_sign_detached(implode("\n", ['provision.v1', $timestamp, $nonce, $body]), $secret);

        return [
            'X-Control-Timestamp' => $timestamp,
            'X-Control-Nonce' => $nonce,
            'X-Control-Signature' => base64_encode($signature),
        ];
    }

    /** @param  array<string, array<int, string>>  $errors */
    private function explain(int $status, array $errors, string $message): string
    {
        $detail = $errors ? ' '.implode(' ', array_merge(...array_values($errors))) : '';

        return match (true) {
            $status === 404 => 'NextBook does not accept new companies from here: CONTROL_PROVISIONING is off there.',
            $status === 401 => 'NextBook refused the signature. Check that its CONTROL_PUBLIC_KEY is this system\'s public key and that the two clocks agree.',
            $status === 403 => 'NextBook requires https for this.',
            $status === 409 => 'NextBook saw this request already. Try again.',
            $status === 422 => 'NextBook did not accept the details.'.$detail,
            $status === 429 => 'NextBook is being asked too often. Wait a minute.',
            default => "NextBook answered {$status}".($message !== '' ? ": {$message}" : '.'),
        };
    }
}
