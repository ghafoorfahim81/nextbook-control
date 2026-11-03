<?php

namespace App\Services\Messaging\Channels;

use App\Models\Message;
use App\Services\Messaging\ChannelException;
use App\Services\Messaging\MessageComposer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp through Meta's Cloud API. A business may only start a conversation
 * with a template Meta has approved, so what is sent is the template's name
 * and the values for its placeholders, not free text.
 *
 * With the `log` driver (the default) nothing leaves the machine: the message
 * is written to the log, so the whole flow can be run and read before a
 * WhatsApp Business account exists.
 */
class WhatsAppChannel
{
    public function send(Message $message): ?string
    {
        if (! $message->to) {
            throw new ChannelException('There is no valid WhatsApp number.');
        }

        return config('control.messaging.whatsapp.driver') === 'cloud'
            ? $this->viaCloud($message)
            : $this->viaLog($message);
    }

    private function viaLog(Message $message): string
    {
        Log::info('WhatsApp (log driver, not sent)', ['to' => $message->to, 'kind' => $message->kind, 'body' => $message->body]);

        return 'log-'.$message->id;
    }

    private function viaCloud(Message $message): string
    {
        $config = config('control.messaging.whatsapp');
        if (! $config['token'] || ! $config['phone_number_id']) {
            throw new ChannelException('WHATSAPP_TOKEN and WHATSAPP_PHONE_NUMBER_ID must be set.');
        }

        $template = $config['templates'][MessageComposer::group($message->kind)] ?? null;
        if (! $template) {
            throw new ChannelException("No WhatsApp template is set for {$message->kind}.");
        }

        try {
            $response = Http::withToken($config['token'])->acceptJson()->timeout((int) $config['timeout'])
                ->post("https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $message->to,
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $message->locale],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], $message->params ?? []),
                        ]],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new ChannelException('WhatsApp could not be reached: '.$e->getMessage(), retryable: true);
        }

        if ($response->successful()) {
            return (string) $response->json('messages.0.id');
        }

        $reason = $response->json('error.message') ?? "WhatsApp answered {$response->status()}.";

        // The provider having a bad moment passes; a wrong number, a template
        // that is not approved or an expired token will not.
        throw new ChannelException($reason, retryable: $response->serverError() || $response->status() === 429);
    }
}
