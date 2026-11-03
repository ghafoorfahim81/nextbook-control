<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * A message could not be delivered. `retryable` tells the queue whether trying
 * again can help: the provider being down can pass, a wrong number or an
 * unapproved template will not.
 */
class ChannelException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
