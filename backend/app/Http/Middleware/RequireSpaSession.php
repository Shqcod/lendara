<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSpaSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'Gunakan aplikasi Lendara dari origin yang diizinkan.',
                'code' => 'FORBIDDEN', 'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
