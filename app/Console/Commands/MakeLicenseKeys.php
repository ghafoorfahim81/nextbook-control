<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Makes the Ed25519 key pair that signs licences, and writes it to .env.
 * Refuses to replace an existing pair: that would make every licence already
 * sent to an ERP fail its check.
 */
class MakeLicenseKeys extends Command
{
    protected $signature = 'control:keys {--force : Replace the existing pair (every ERP then needs the new public key)}';

    protected $description = 'Generate the key pair that signs licences';

    public function handle(): int
    {
        if (config('control.license.private_key') && ! $this->option('force')) {
            $this->error('A key pair already exists. Use --force only if you mean to replace it.');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $private = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        $path = base_path('.env');
        $env = is_file($path) ? file_get_contents($path) : '';
        foreach (['LICENSE_PRIVATE_KEY' => $private, 'LICENSE_PUBLIC_KEY' => $public] as $key => $value) {
            $env = preg_match("/^{$key}=.*$/m", $env)
                ? preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $env)
                : rtrim($env)."\n{$key}={$value}\n";
        }
        file_put_contents($path, $env);

        $this->info('Keys written to .env.');
        $this->line('Public key (give this to each NextBook): '.$public);

        return self::SUCCESS;
    }
}
