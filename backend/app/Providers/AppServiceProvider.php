<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // First-party cookie authentication only. Do not enable bearer tokens
        // or create personal_access_tokens as a shortcut around session controls.
        Sanctum::getAccessTokenFromRequestUsing(static fn () => null);
    }
}
