<?php

namespace App\Services\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Message;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Decides who hears about something and writes a message for each way they can
 * be reached; the sending itself is a queued job, so a slow mail server never
 * holds up a screen.
 *
 * A message is identified by what it is about (customer, kind, channel,
 * contact, and the cycle it belongs to, such as the subscription's end date).
 * Writing the same one again does nothing, so the daily run can be repeated, or
 * run a day late, without anyone being written to twice.
 */
class Notifier
{
    public function __construct(private readonly MessageComposer $composer)
    {
    }

    /**
     * Who is written to about a subscription and its money: the people marked
     * as the manager or finance contact, and the primary contact.
     *
     * @return Collection<int, Contact>
     */
    public function recipients(Customer $customer): Collection
    {
        return $customer->contacts()->get()
            ->filter(fn (Contact $contact) => $contact->is_primary || in_array($contact->role, ['manager', 'finance'], true))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data  what the text needs (package, date, days, reason, amount, number)
     * @param  string  $cycle  what makes this the same message again (e.g. the end date it is about)
     * @return array{queued: int, skipped: int, duplicates: int}
     */
    public function notify(string $kind, Customer $customer, array $data, string $cycle): array
    {
        $text = $this->composer->compose($kind, $customer, $data);
        $result = ['queued' => 0, 'skipped' => 0, 'duplicates' => 0];

        $targets = [];
        foreach ($this->recipients($customer) as $contact) {
            if ($contact->wants_email && $contact->email) {
                $targets[] = [$contact, 'email', $contact->email];
            }
            if ($contact->wants_whatsapp) {
                $number = Phone::e164($contact->whatsapp ?: $contact->phone, (string) config('control.messaging.default_country_code'));
                if ($number) {
                    $targets[] = [$contact, 'whatsapp', $number];
                }
            }
        }

        // Nobody to write to is worth knowing: it is shown, once per cycle.
        if (! $targets) {
            $created = $this->record($customer, null, $kind, 'none', null, $text, "{$customer->id}|{$kind}|none|{$cycle}", [
                'status' => 'skipped', 'error' => 'No contact with an email address or a WhatsApp number to notify.',
            ]);
            $result[$created ? 'skipped' : 'duplicates']++;

            return $result;
        }

        foreach ($targets as [$contact, $channel, $to]) {
            $message = $this->record($customer, $contact, $kind, $channel, $to, $text, "{$customer->id}|{$kind}|{$channel}|{$contact->id}|{$cycle}");

            if ($message === null) {
                $result['duplicates']++;

                continue;
            }

            SendMessageJob::dispatch($message->id)->afterCommit();
            $result['queued']++;
        }

        return $result;
    }

    /** Writes the message unless one about the same thing exists; null when it did. */
    private function record(Customer $customer, ?Contact $contact, string $kind, string $channel, ?string $to, array $text, string $key, array $extra = []): ?Message
    {
        if (Message::query()->where('dedupe_key', $key)->exists()) {
            return null;
        }

        try {
            return Message::query()->create([
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id,
                'kind' => $kind,
                'channel' => $channel,
                'to' => $to,
                'locale' => $text['locale'],
                'subject' => $text['subject'],
                'body' => $text['body'],
                'params' => $text['params'],
                'dedupe_key' => $key,
            ] + $extra);
        } catch (UniqueConstraintViolationException) {
            // Another run wrote it between the check and the insert.
            return null;
        }
    }
}
