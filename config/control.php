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
];
