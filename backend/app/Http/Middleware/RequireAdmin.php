<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;

class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()->is_active && $request->user()->role === Role::Admin, 403);

        return $next($request);
    }
}
