<?php

return [
    /*
    | The key pair that signs the licences sent to each NextBook. Make one with
    | `php artisan control:keys`. The private key stays here; the public key is
    | what an ERP is given to check a licence with.
    */
    'license' => [
        'private_key' => env('LICENSE_PRIVATE_KEY'),
        'public_key' => env('LICENSE_PUBLIC_KEY'),
    ],

    // How long an ERP keeps working on its last licence if it cannot reach this
    // system. Sent to the ERP so the two sides agree.
    'grace_days' => (int) env('LICENSE_GRACE_DAYS', 7),

    /*
    | Messages to customers (reminders and notices). Email goes through the
    | MAIL_* settings. WhatsApp goes through Meta's WhatsApp Cloud API, which
    | only lets a business start a conversation with a template Meta has
    | approved, so each kind of message names its template here.
    |
    | WHATSAPP_DRIVER is `log` until you have a WhatsApp Business account: the
    | message is written to the log and the screens show it, but nothing is
    | sent. Set it to `cloud` and fill in the two WHATSAPP_ values to send.
    */
    'messaging' => [
        // Days before the end at which a reminder goes out, nearest first.
        'reminder_days' => [30, 14, 7, 1],
        // Days after the end during which "it has ended" is still sent. After
        // that a lapsed customer is a matter for a person, not a reminder.
        'expired_window_days' => 3,
        // Notices sent when something happens, not on a schedule.
        'events' => ['blocked', 'unblocked', 'payment_received'],
        'timezone' => env('MESSAGING_TIMEZONE', 'Asia/Kabul'),
        'default_country_code' => env('MESSAGING_COUNTRY_CODE', '93'),

        'whatsapp' => [
            'driver' => env('WHATSAPP_DRIVER', 'log'),          // log | cloud
            'token' => env('WHATSAPP_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
            'timeout' => 15,
            // The approved template for each kind of message. The body of each
            // takes the values listed for it in App\Services\Messaging\MessageComposer::PARAMS,
            // in that order, as {{1}}, {{2}}…
            'templates' => [
                'expiring' => env('WHATSAPP_TEMPLATE_EXPIRING', 'nextbook_expiring'),
                'expired' => env('WHATSAPP_TEMPLATE_EXPIRED', 'nextbook_expired'),
                'blocked' => env('WHATSAPP_TEMPLATE_BLOCKED', 'nextbook_blocked'),
                'unblocked' => env('WHATSAPP_TEMPLATE_UNBLOCKED', 'nextbook_unblocked'),
                'payment_received' => env('WHATSAPP_TEMPLATE_PAYMENT', 'nextbook_payment_received'),
            ],
        ],
    ],
];
