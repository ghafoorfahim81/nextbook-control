<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messaging\ChannelException;
use App\Services\Messaging\Channels\EmailChannel;
use App\Services\Messaging\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one message and records how it went. A problem that can pass (the mail
 * server or WhatsApp being down) is tried again a little later; one that will
 * not (a wrong number, a template Meta has not approved) is marked failed at
 * once so a person sees it, instead of being retried for nothing.
 */
class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $messageId)
    {
    }

    /** @return array<int, int> seconds to wait before the second and third try */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EmailChannel $email, WhatsAppChannel $whatsapp): void
    {
        $message = Message::query()->find($this->messageId);

        // Gone, or already delivered (the job ran twice): nothing to do.
        if (! $message || $message->status === 'sent') {
            return;
        }

        $message->increment('attempts');

        try {
            $providerId = $message->channel === 'whatsapp' ? $whatsapp->send($message) : $email->send($message);
            $message->update(['status' => 'sent', 'sent_at' => now(), 'provider_id' => $providerId, 'error' => null]);
        } catch (ChannelException $e) {
            $message->update(['error' => $e->getMessage()]);

            // Retrying only makes sense on a real queue; run inline (tests) it would just repeat at once.
            $canRetry = $e->retryable && $this->job !== null && ! ($this->job instanceof SyncJob) && $this->attempts() < $this->tries;
            if ($canRetry) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

                return;
            }

            $message->update(['status' => 'failed']);
        }
    }

    /** Anything unexpected that got past handle(). */
    public function failed(Throwable $e): void
    {
        Message::query()->whereKey($this->messageId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
