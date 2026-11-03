<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\User;
use App\Services\LicenseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks that this installation is set up the way it must be, and says plainly
 * what is not. Run it after installing, after changing .env, and whenever
 * something seems quiet (no reminders, no mail).
 *
 *   php artisan control:doctor
 *
 * Exit code 1 if anything FAILs, so a deploy script can stop on it. A WARN is
 * something to look at, not a blocker.
 */
class Doctor extends Command
{
    protected $signature = 'control:doctor';

    protected $description = 'Check that this installation is set up correctly';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        // The command object lives as long as the process, so a second run in it must start from zero.
        $this->failures = 0;
        $this->warnings = 0;

        $production = app()->environment('production');
        $this->line($production ? 'Environment: production' : 'Environment: '.app()->environment().' (not production: some checks are only warnings)');
        $this->newLine();

        // --- The application -------------------------------------------------
        $this->check(config('app.key') ? ['ok', 'APP_KEY is set.'] : ['fail', 'APP_KEY is not set: run php artisan key:generate.']);

        $debug = (bool) config('app.debug');
        $this->check(match (true) {
            $debug && $production => ['fail', 'APP_DEBUG is on in production: an error page would show passwords and keys. Set APP_DEBUG=false.'],
            $debug => ['warn', 'APP_DEBUG is on (fine here, never in production).'],
            default => ['ok', 'APP_DEBUG is off.'],
        });

        $url = (string) config('app.url');
        $https = str_starts_with($url, 'https://');
        $this->check($https ? ['ok', "APP_URL is HTTPS ({$url})."] : [$production ? 'warn' : 'info', "APP_URL is not HTTPS ({$url}): staff passwords and codes would cross the network in the clear."]);

        if ($https) {
            $this->check(config('session.secure') === true
                ? ['ok', 'Session cookies are marked secure.']
                : ['warn', 'SESSION_SECURE_COOKIE is not true: the session cookie could be sent over plain HTTP. Set SESSION_SECURE_COOKIE=true.']);
        }

        $this->check(config('trustedproxy.proxies')
            ? ['ok', 'TRUSTED_PROXIES is set.']
            : ['info', 'TRUSTED_PROXIES is not set. If this sits behind nginx or a load balancer, set it (see docs/deployment-fa.md), or every visitor looks like the proxy.']);

        $this->check(in_array((string) config('control.messaging.timezone'), \DateTimeZone::listIdentifiers(), true)
            ? ['ok', 'Messaging timezone is '.config('control.messaging.timezone').'.']
            : ['fail', 'MESSAGING_TIMEZONE is not a real timezone.']);

        $logs = storage_path('logs');
        $this->check(is_writable($logs) && is_writable(storage_path('framework')) ? ['ok', 'storage/ is writable.'] : ['fail', 'storage/ is not writable by this user.']);

        // --- The database ----------------------------------------------------
        try {
            DB::connection()->getPdo();
            $driver = DB::connection()->getDriverName();
            $this->check($driver === 'pgsql' ? ['ok', 'Database reachable (PostgreSQL).'] : [$production ? 'warn' : 'info', "Database reachable, but it is {$driver}, not PostgreSQL."]);

            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
            $pending = array_diff($files, $migrator->getRepository()->getRan());
            $this->check($pending === [] ? ['ok', 'All migrations have run.'] : ['fail', count($pending).' migration(s) have not run: php artisan migrate --force.']);

            $owners = User::query()->where('role', 'owner')->where('is_active', true)->get();
            $this->check(match (true) {
                $owners->isEmpty() => ['fail', 'There is no active owner: php artisan control:staff you@example.com owner'],
                $owners->filter->hasTwoFactor()->isEmpty() => ['warn', 'No owner has set up two-factor yet (they are asked at their first sign-in).'],
                default => ['ok', $owners->count().' active owner(s); two-factor is on for '.$owners->filter->hasTwoFactor()->count().'.'],
            });

            $failed = Message::query()->where('status', 'failed')->count();
            $this->check($failed === 0 ? ['ok', 'No message has failed.'] : ['warn', "{$failed} message(s) failed: see the customer pages, or the dashboard."]);

            $this->queue();
        } catch (Throwable $e) {
            $this->check(['fail', 'The database could not be used: '.$e->getMessage()]);
        }

        // --- Licences --------------------------------------------------------
        $this->licenceKeys();

        // --- Messages --------------------------------------------------------
        $mailer = (string) config('mail.default');
        $this->check(in_array($mailer, ['log', 'array'], true)
            ? [$production ? 'warn' : 'info', "MAIL_MAILER is {$mailer}: emails are only written to the log, none is sent."]
            : ['ok', "Email goes out through {$mailer}."]);

        $whatsapp = config('control.messaging.whatsapp');
        if ($whatsapp['driver'] === 'cloud') {
            $missing = array_filter([
                empty($whatsapp['token']) ? 'WHATSAPP_TOKEN' : null,
                empty($whatsapp['phone_number_id']) ? 'WHATSAPP_PHONE_NUMBER_ID' : null,
            ]);
            $noTemplate = array_keys(array_filter($whatsapp['templates'], fn ($name) => empty($name)));
            $this->check(match (true) {
                $missing !== [] => ['fail', 'WhatsApp is set to cloud but '.implode(' and ', $missing).' is empty.'],
                $noTemplate !== [] => ['fail', 'WhatsApp has no template name for: '.implode(', ', $noTemplate).'.'],
                default => ['ok', 'WhatsApp is set up through the Cloud API.'],
            });
        } else {
            $this->check([$production ? 'warn' : 'info', 'WHATSAPP_DRIVER is log: WhatsApp messages are written to the log, none is sent.']);
        }

        $this->newLine();
        $this->line($this->failures === 0
            ? ($this->warnings === 0 ? 'Everything is in order.' : "No failures, {$this->warnings} warning(s).")
            : "{$this->failures} failure(s), {$this->warnings} warning(s). Fix the FAIL lines first.");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The queue worker and the scheduler, which have to be running or no message ever leaves. */
    private function queue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->check(['warn', 'QUEUE_CONNECTION is sync: messages are sent while the page waits, and a slow mail server slows the screen. Use database.']);
        } elseif ($connection === 'database') {
            $oldest = DB::table('jobs')->min('created_at');
            $waiting = DB::table('jobs')->count();
            $this->check(match (true) {
                $oldest !== null && (now()->timestamp - (int) $oldest) > 600 => ['warn', "{$waiting} job(s) have been waiting more than 10 minutes: is php artisan queue:work running?"],
                default => ['ok', "Queue is database; {$waiting} job(s) waiting."],
            });

            $failedJobs = DB::table('failed_jobs')->count();
            $this->check($failedJobs === 0 ? ['ok', 'No job has failed.'] : ['warn', "{$failedJobs} job(s) failed: php artisan queue:failed"]);
        } else {
            $this->check(['info', "Queue connection is {$connection}."]);
        }

        $beat = Cache::get('control:scheduler-heartbeat');
        $this->check(match (true) {
            $beat === null => ['warn', 'The scheduler has never run. Is the cron line there? (* * * * * php artisan schedule:run) Reminders are sent by it.'],
            (now()->timestamp - (int) $beat) > 300 => ['warn', 'The scheduler last ran '.(int) ((now()->timestamp - (int) $beat) / 60).' minutes ago: is the cron still running?'],
            default => ['ok', 'The scheduler is running.'],
        });
    }

    /**
     * The keys licences are signed with. A pair that does not match is the
     * worst mistake available: every NextBook would be given licences it
     * refuses, and none could be told why.
     */
    private function licenceKeys(): void
    {
        $private = base64_decode((string) config('control.license.private_key'), true);
        $public = (string) config('control.license.public_key');

        if (! $private || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->check(['fail', 'LICENSE_PRIVATE_KEY is missing or damaged: php artisan control:keys']);

            return;
        }

        if (base64_encode(sodium_crypto_sign_publickey_from_secretkey($private)) !== $public) {
            $this->check(['fail', 'LICENSE_PUBLIC_KEY is not the public half of LICENSE_PRIVATE_KEY: every NextBook would refuse the licences. Give the NextBooks this key instead: '.base64_encode(sodium_crypto_sign_publickey_from_secretkey($private))]);

            return;
        }

        // The whole round trip, as a NextBook does it.
        $payload = ['probe' => true];
        $ok = LicenseService::verify($payload, app(LicenseService::class)->sign($payload), $public);
        $this->check($ok ? ['ok', 'Licence keys match and sign correctly.'] : ['fail', 'A licence signed here does not verify with the public key.']);
    }

    /** @param  array{0: string, 1: string}  $result */
    private function check(array $result): void
    {
        [$level, $message] = $result;

        if ($level === 'ok') {
            $this->line('  <info> ok </info>  '.$message);
        } elseif ($level === 'info') {
            $this->line('  info  '.$message);
        } elseif ($level === 'warn') {
            $this->warnings++;
            $this->line('  <comment>WARN</comment>  '.$message);
        } else {
            $this->failures++;
            $this->line('  <error> FAIL </error>  '.$message);
        }
    }
}
