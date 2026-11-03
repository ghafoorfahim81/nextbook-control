<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A member of staff whose password was chosen or seen by someone else (a new
 * account, or after a reset) can do nothing until they have set their own.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('account.password', 'account.password.update')) {
            return redirect()->route('account.password');
        }

        return $next($request);
    }
}
