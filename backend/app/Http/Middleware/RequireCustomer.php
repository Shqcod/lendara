<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()->role === Role::Customer, 403);

        return $next($request);
    }
}
