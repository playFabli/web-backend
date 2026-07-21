<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RouteGuardOnlyGuest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('Authorization')) {
            return response()->json(['status' => 'error', 'message' => 'Authenticated users cannot access this route.'], 403);
        }

        return $next($request);
    }
}
