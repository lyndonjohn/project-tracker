<?php

use App\Exceptions\StaleProjectException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 409 rather than 422: the payload was valid, the row simply moved on. The current
        // version comes back so a client can re-read, merge and retry without guessing.
        // Scoped to the API because the Livewire pages catch this themselves and answer
        // with a toast that leaves the user's typing in the form.
        $exceptions->render(fn (StaleProjectException $exception, Request $request) => $request->is('api/*')
            ? response()->json([
                'message' => $exception->getMessage(),
                'version' => $exception->current?->version,
            ], Response::HTTP_CONFLICT)
            : null);
    })->create();
