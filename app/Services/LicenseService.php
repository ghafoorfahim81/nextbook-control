<?php

namespace App\Services;

use App\Exceptions\ControlException;
use App\Models\Customer;
use App\Models\Subscription;

/**
 * Builds and signs the licence a customer's NextBook receives: which modules,
 * which limits, until when, and whether it may write.
 *
 * The signature is Ed25519 over the canonical JSON of the payload. The control
 * center keeps the private key; an ERP holds only the public key, so it can
 * check a licence is genuine but never make one. `verify` is the whole of what
 * an ERP needs to do.
 */
class LicenseService
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public function payload(Customer $customer): array
    {
        $subscription = Subscription::query()->with('package')->where('customer_id', $customer->id)->first();
        if (! $subscription) {
            throw new ControlException(__('control.no_subscription'));
        }

        $limits = $subscription->limits();

        return [
            'v' => self::VERSION,
            'customer_id' => $customer->id,
            'erp_company_id' => $customer->instance?->erp_company_id,
            'license_version' => $subscription->license_version,
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'customer_status' => $customer->status,
            'state' => $subscription->state(),
            'kind' => $subscription->kind,
            'starts_on' => $subscription->starts_on->toDateString(),
            'ends_on' => $subscription->ends_on->toDateString(),
            'writes_allowed' => $subscription->allowsWrites(),
            'block_reason' => $subscription->isBlocked() ? $subscription->block_reason : null,
            'modules' => array_values($limits['modules']),
            'max_users' => $limits['max_users'],
            'max_branches' => $limits['max_branches'],
            'max_storage_mb' => $limits['max_storage_mb'],
            'max_monthly_documents' => $limits['max_monthly_documents'],
        ];
    }

    /**
     * The payload and its signature, as sent to the ERP.
     *
     * @return array{payload: array<string, mixed>, signature: string}
     */
    public function issue(Customer $customer): array
    {
        $payload = $this->payload($customer);

        return ['payload' => $payload, 'signature' => $this->sign($payload)];
    }

    /** @param  array<string, mixed>  $payload */
    public function sign(array $payload): string
    {
        $secret = base64_decode((string) config('control.license.private_key'), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ControlException('The licence signing key is missing: run php artisan control:keys.');
        }

        return base64_encode(sodium_crypto_sign_detached(self::canonical($payload), $secret));
    }

    /**
     * What an ERP runs on a licence it received. True only if the payload is
     * exactly what was signed, by the holder of the matching private key.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function verify(array $payload, string $signature, string $publicKey): bool
    {
        $public = base64_decode($publicKey, true);
        $sig = base64_decode($signature, true);
        if ($public === false || $sig === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::canonical($payload), $public);
    }

    /** The same bytes whoever builds them: keys sorted at every level, no escaping that varies. */
    public static function canonical(array $payload): string
    {
        $sort = function (array $value) use (&$sort): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return json_encode($sort($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
