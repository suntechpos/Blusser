<?php

namespace App\Providers;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Session\VersionedSessionHandler;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Session::extend('versioned', fn ($app) => new VersionedSessionHandler(DB::connection('pgsql'), 'sessions', config('session.lifetime'), $app));
        Auth::extend('versioned-cookie', function ($app, $name, $config) {
            $guard = new RequestGuard(function (Request $request) {
                if (! $request->hasSession() || ! is_array($identity = $request->session()->get('identity'))) {
                    return null;
                }
                $raw = app(Governance::class)->call('session_identity', [$identity['id'], $identity['version']]);

                return $raw === null ? null : (new User)->forceFill(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
            }, $app['request'], Auth::createUserProvider($config['provider']));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
        // First-party cookie authentication only. Do not enable bearer tokens
        // or create personal_access_tokens as a shortcut around session controls.
        Sanctum::getAccessTokenFromRequestUsing(static fn () => null);
    }
}
