<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            if ($request->hasSession()) {
                auth()->guard('web')->logout();
            }

            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        return $next($request);
    }
}
