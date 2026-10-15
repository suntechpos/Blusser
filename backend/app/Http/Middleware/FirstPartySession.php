<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class FirstPartySession
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->attributes->get('sanctum') && $request->hasSession(), 403, 'First-party cookie session required.');

        return $next($request);
    }
}
