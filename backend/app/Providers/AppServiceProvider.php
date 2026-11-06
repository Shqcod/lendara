<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Aplikasi ini hanya memakai cookie SPA, tanpa penerbitan bearer token.
        Sanctum::getAccessTokenFromRequestUsing(fn (Request $request) => null);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            hash('sha256', mb_strtolower((string) $request->input('email')).'|'.$request->ip())
        ));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by(
            hash('sha256', (string) $request->ip())
        ));
    }
}
