<?php

namespace App\Http\Middleware;

use App\Services\ReviewScope;
use Closure;
use Illuminate\Http\Request;

class RequireReviewer
{
    public function handle(Request $request, Closure $next)
    {
        app(ReviewScope::class)->context($request->user());

        return $next($request);
    }
}
