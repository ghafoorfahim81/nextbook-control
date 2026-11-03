<?php

return [
    'ends_before_start' => 'The end date cannot be before the start date.',
    'reason_required' => 'A reason is required.',
    'no_price' => 'The package :package has no price in :currency.',
    'discount_too_big' => 'The discount cannot be more than the amount.',
    'invoice_has_payments' => 'This invoice has payments; remove them before voiding it.',
    'invoice_void' => 'This invoice is void; no payment can be recorded on it.',
    'payment_positive' => 'The payment must be more than zero.',
    'overpayment' => 'The payment is more than what is left to pay (:balance).',
    'no_subscription' => 'This customer has no subscription, so there is no licence to issue.',
    'saved' => 'Saved.',
    'contact' => ['bad_number' => 'The :field number is not a valid phone number.'],
    'reminder' => [
        'too_soon' => 'A reminder was just sent to this customer. Try again in :minutes minutes.',
        'nobody' => 'Nothing was sent: this customer has no contact with an email address or a WhatsApp number to write to.',
        'sent' => ':count message(s) queued.',
    ],
    'message' => ['only_failed' => 'Only a message that failed can be tried again.'],
    'staff' => [
        'self_off' => 'You cannot switch yourself off.',
        'last_owner' => 'This is the last active owner: make someone else an owner first.',
    ],
    'auth' => [
        'failed' => 'These credentials do not match, or the account is switched off.',
        'throttle' => 'Too many attempts. Try again in :seconds seconds.',
        'invalid_code' => 'That code is not valid.',
    ],
];
