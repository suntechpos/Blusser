<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class FullIdentity
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()->must_change_password, 403, 'Password change required.');

        return $next($request);
    }
}
