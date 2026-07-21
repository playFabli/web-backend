<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RouteGuardOnlyAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->header('Authorization')) {
            return response()->json(['status' => 'error', 'message' => 'Authentication required to access this route.'], 401);
        }

        $token = str_replace("Bearer ", "", $request->header('Authorization'));
        $userToken = \App\Models\UserToken::where('token', $token)->first();
        if (!$userToken || $userToken->expires_at < now()) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired token.'], 401);
        }

        return $next($request);

    }
}
