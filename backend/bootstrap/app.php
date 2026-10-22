<?php

use App\Http\Middleware\FirstPartySession;
use App\Http\Middleware\FullIdentity;
use App\Http\Middleware\NoStore;
use App\Modules\Access\Console\AccessDemo;
use App\Modules\Access\Console\ClinicRoles;
use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Patients\Console\BackfillCeoPermissions;
use App\Modules\Platform\Console\CreatePlatformOperator;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
    )
    ->withCommands([ClinicRoles::class, AccessDemo::class, CreatePlatformOperator::class, BackfillCeoPermissions::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trimStrings(except: ['password', 'password_confirmation', 'current_password', 'temporary_password', 'token']);
        $middleware->append(NoStore::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, FirstPartySession::class);
        $middleware->alias(['first-party' => FirstPartySession::class, 'full-identity' => FullIdentity::class]);
        $middleware->preventRequestForgery(except: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([GovernanceDenied::class]);
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'temporary_password', 'token']);
        $exceptions->render(function (GovernanceDenied $exception, Request $request) {
            $status = match ($exception->reason) {
                'precondition' => 428, 'credentials' => 401, 'rate_limited' => 429, 'conflict' => 409, 'not_found' => 404, 'invalid_input' => 422, default => 403
            };

            return response()->json(['message' => 'Operation not authorized or unavailable.'], $status);
        });
        $exceptions->render(fn (InvalidArgumentException $exception, Request $request) => response()->json(['message' => 'Invalid input.'], 422));
        $exceptions->respond(function ($response) {
            if (request()->is('api/*', 'sanctum/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
