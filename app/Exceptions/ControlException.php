<?php

namespace App\Exceptions;

use RuntimeException;

/** A rule of the control center was broken: the message is meant for the staff member. */
class ControlException extends RuntimeException
{
}
