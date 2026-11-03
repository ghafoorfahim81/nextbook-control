<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor is required of every staff account. A signed-in user who has not
 * finished setting it up can do nothing else until they have.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
