<?php

namespace App\Services\Messaging;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;

/**
 * Turns a kind of message and a customer into the words that go out, in the
 * customer's own language: a subject and a body for email, and the ordered
 * values a WhatsApp template is filled with.
 */
class MessageComposer
{
    private const DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /**
     * The values each template takes, in order ({{1}}, {{2}}…). The templates
     * registered with WhatsApp must have exactly these placeholders, in this order.
     */
    public const PARAMS = [
        'expiring' => ['customer', 'package', 'date', 'days'],
        'expired' => ['customer', 'package', 'date'],
        'blocked' => ['customer', 'reason'],
        'unblocked' => ['customer'],
        'payment_received' => ['customer', 'amount', 'number'],
    ];

    /** expiring_30 and expiring_7 are one message with a different number of days. */
    public static function group(string $kind): string
    {
        return str_starts_with($kind, 'expiring_') ? 'expiring' : $kind;
    }

    /**
     * @param  array<string, mixed>  $data  package, date (Carbon), days, reason, amount, number
     * @return array{subject: string, body: string, params: array<int, string>, group: string, locale: string}
     */
    public function compose(string $kind, Customer $customer, array $data = []): array
    {
        $group = self::group($kind);
        $locale = in_array($customer->locale, ['fa', 'ps', 'en'], true) ? $customer->locale : 'fa';

        $values = [
            'customer' => $customer->displayName(),
            'package' => (string) ($data['package'] ?? ''),
            'date' => isset($data['date']) ? $this->date($data['date'], $locale) : '',
            'days' => isset($data['days']) ? $this->number((string) $data['days'], $locale) : '',
            'reason' => (string) ($data['reason'] ?? '-'),
            'amount' => (string) ($data['amount'] ?? ''),
            'number' => (string) ($data['number'] ?? ''),
        ];

        return [
            'subject' => __("messaging.{$group}.subject", [], $locale),
            'body' => __("messaging.{$group}.body", $values, $locale),
            'params' => array_map(fn (string $name) => $values[$name], self::PARAMS[$group]),
            'group' => $group,
            'locale' => $locale,
        ];
    }

    /** Jalali with Persian digits for Dari and Pashto, plain ISO for English. */
    public function date(Carbon $date, string $locale): string
    {
        if ($locale === 'en') {
            return $date->toDateString();
        }

        return $this->number(Jalalian::fromCarbon($date)->format('Y/m/d'), $locale);
    }

    private function number(string $text, string $locale): string
    {
        return $locale === 'en' ? $text : str_replace(range(0, 9), self::DIGITS, $text);
    }
}
