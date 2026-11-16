<?php

namespace App\Http\Middleware;

use App\Services\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Akun dinonaktifkan. Hubungi administrator.',
                'code' => 'ACCOUNT_INACTIVE', 'errors' => (object) [],
            ], 403);
        }

        if ((int) $request->session()->get('access_generation', 0) !== app(AccountAccess::class)->generation($request->user()->id)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Sesi telah dicabut. Silakan masuk kembali.', 'code' => 'SESSION_REVOKED', 'errors' => (object) []], 401);
        }

        return $next($request);
    }
}
