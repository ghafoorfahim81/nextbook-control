<?php

namespace App\Services\Messaging\Channels;

use App\Models\Message;
use App\Services\Messaging\ChannelException;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class EmailChannel
{
    /** Sends the message; returns the provider's id for it, if the mailer has one. */
    public function send(Message $message): ?string
    {
        if (! $message->to) {
            throw new ChannelException('There is no email address.');
        }

        $html = view('emails.message', [
            'body' => $message->body,
            'rtl' => $message->locale !== 'en',
        ])->render();

        try {
            Mail::html($html, function ($mail) use ($message) {
                $mail->to($message->to)->subject((string) $message->subject);
                // Plain text beside the HTML, for mail programs that prefer it.
                $mail->getSymfonyMessage()->text($message->body);
            });
        } catch (TransportExceptionInterface $e) {
            // The mail server being down passes; try again.
            throw new ChannelException($e->getMessage(), retryable: true);
        } catch (Throwable $e) {
            throw new ChannelException($e->getMessage());
        }

        return null;
    }
}
