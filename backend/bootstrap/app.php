<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'sanctum/*') || $request->expectsJson());
        $exceptions->render(function (ValidationException $error, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Data belum valid.', 'code' => 'VALIDATION_ERROR',
                    'errors' => $error->errors(),
                ], 422);
            }
        });
        $exceptions->render(function (AuthenticationException $error, Request $request) {
            return response()->json([
                'message' => 'Silakan masuk untuk melanjutkan.',
                'code' => 'UNAUTHENTICATED', 'errors' => (object) [],
            ], 401);
        });
        $exceptions->render(function (HttpExceptionInterface $error, Request $request) {
            if (! $request->is('api/*', 'sanctum/*')) {
                return null;
            }
            [$code, $message] = match ($error->getStatusCode()) {
                403 => ['FORBIDDEN', 'Akses tidak diizinkan.'],
                404 => ['NOT_FOUND', 'Data tidak ditemukan.'],
                405 => ['METHOD_NOT_ALLOWED', 'Metode tidak diizinkan.'],
                413 => ['PAYLOAD_TOO_LARGE', 'Unggahan terlalu besar. Maksimal satu berkas 5 MB per permintaan.'],
                419 => ['CSRF_TOKEN_MISMATCH', 'Sesi keamanan berakhir. Muat ulang sesi sebelum mencoba lagi.'],
                429 => ['TOO_MANY_REQUESTS', 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.'],
                default => ['HTTP_ERROR', 'Permintaan gagal diproses.'],
            };

            return response()->json([
                'message' => $message, 'code' => $code, 'errors' => (object) [],
            ], $error->getStatusCode(), $error->getHeaders());
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($request->is('api/*', 'sanctum/*')) {
                return response()->json([
                    'message' => 'Layanan belum dapat memproses permintaan. Silakan coba lagi nanti.',
                    'code' => 'SERVER_ERROR', 'errors' => (object) [],
                ], 500);
            }
        });
    })->create();
