<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);
        $middleware->alias([
            'instance' => \App\Http\Middleware\AuthenticateInstance::class,
            'two_factor' => \App\Http\Middleware\RequireTwoFactor::class,
            'active_staff' => \App\Http\Middleware\LogOutInactiveStaff::class,
            'password_changed' => \App\Http\Middleware\RequirePasswordChange::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A rule of the control center said no (an overpayment, a void invoice,
        // no price): tell the staff member and stay on the page. The API answers
        // in JSON instead.
        $exceptions->render(function (\App\Exceptions\ControlException $e, \Illuminate\Http\Request $request) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        });
    })->create();
