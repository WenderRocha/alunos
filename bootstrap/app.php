<?php

declare(strict_types = 1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // O Coolify termina o TLS no proxy e encaminha a requisição ao
        // container via HTTP. Confiar no proxy permite ao Laravel respeitar
        // o X-Forwarded-Proto e gerar URLs/assets em HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('billing:process')
            ->dailyAt((string) config('billing.process_time', '08:00'))
            ->withoutOverlapping();
    })
    ->create();
