<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a customer's NextBook in by the token issued for its instance. The
 * request then belongs to that one customer and cannot reach another's.
 *
 * Only the hash of a token is stored, so a stolen database does not give the
 * tokens. A customer that has been archived is refused: it has no licence to
 * pull any more.
 */
class AuthenticateInstance
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $instance = $token ? Instance::findByToken($token) : null;

        if (! $instance) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer = $instance->customer;
        if (! $customer || $customer->status === 'archived') {
            return response()->json(['message' => 'This customer has no access.'], 403);
        }

        $request->attributes->set('instance', $instance);

        return $next($request);
    }
}
