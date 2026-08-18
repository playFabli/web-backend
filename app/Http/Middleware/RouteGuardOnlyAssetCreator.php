<?php

namespace App\Http\Middleware;

use App\Models\UserToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RouteGuardOnlyAssetCreator
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->header('Authorization')) {
            return response()->json(['status' => 'error', 'message' => 'Authentication required to access this route.'], 401);
        }

        $token = str_replace('Bearer ', '', $request->header('Authorization'));
        $userToken = UserToken::where('token', $token)->first();
        if (! $userToken || $userToken->expires_at < now()) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired token.'], 401);
        }

        $user = $userToken->user;
        if (! in_array($user->role, ['admin', 'moderator', 'asset_creator'])) {
            return response()->json(['status' => 'error', 'message' => 'You do not have permission to access this route.'], 403);
        }

        return $next($request);
    }
}
