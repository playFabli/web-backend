<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use App\Models\UserToken;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GlobalSiteMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('Authorization')) {
            $token = str_replace('Bearer ', '', $request->header('Authorization'));
            $userToken = UserToken::where('token', $token)->first();
            if (! $userToken || $userToken->expires_at < now()) {
                return $next($request);
            }

            $user = $userToken->user;
            $user->last_seen_at = now();

            if (! $user->last_currency_at || Carbon::parse($user->last_currency_at)->lte(now()->subDay())) {
                $settings = SiteSetting::find(1);

                $user->coins = $user->coins + round($settings->daily_bonus * $this->getMultiplier($user->level));
                $user->last_currency_at = now();
            }

            while ($user->exp >= $user->expNeeded()) {
                $user->exp -= $user->expNeeded();
                $user->level += 1;
            }

            $user->save();
        }

        return $next($request);
    }

    private function getMultiplier(int $level)
    {
        if ($level < 2) {
            return 1.0;
        } // base rate

        return 1 + ($level - 2) * 0.25;
    }
}
