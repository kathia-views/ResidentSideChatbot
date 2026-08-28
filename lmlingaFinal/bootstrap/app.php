<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'ui.role' => \App\Http\Middleware\PersistUiRole::class,
            'ui.admin' => \App\Http\Middleware\EnsureAdminRole::class,
            'resident.chatbot' => \App\Http\Middleware\EnsureResidentChatbotAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Health Worker Edit uses hw_* password field names (frozen UI contract).
        $exceptions->dontFlash([
            'hw_password',
            'hw_password_confirmation',
        ]);
    })->create();
