<?php

return [
    'expiring' => [
        'subject' => 'Your NextBook subscription is ending soon',
        'body' => "Hello, :customer\nYour subscription (:package) ends on :date (:days days left). Contact us to renew so your work carries on without a break.\nNextBook",
    ],
    'expired' => [
        'subject' => 'Your NextBook subscription has ended',
        'body' => "Hello, :customer\nYour subscription (:package) ended on :date. You can still read and print everything, but you cannot add or change anything until it is renewed. Contact us to renew.\nNextBook",
    ],
    'blocked' => [
        'subject' => 'Your NextBook account is blocked',
        'body' => "Hello, :customer\nYour account has been blocked. Reason: :reason\nYou can still read and print everything, but you cannot add or change anything. Contact us to have it lifted.\nNextBook",
    ],
    'unblocked' => [
        'subject' => 'Your NextBook account is open again',
        'body' => "Hello, :customer\nThe block on your account has been lifted and you can work in full again.\nNextBook",
    ],
    'payment_received' => [
        'subject' => 'Your payment was recorded',
        'body' => "Hello, :customer\nYour payment of :amount for invoice :number was recorded. Thank you.\nNextBook",
    ],
];
