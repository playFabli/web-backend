<?php

namespace App\Providers;

use App\Models\UserToken;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton('token_user', function ($app) {
            $token = str_replace('Bearer ', '', $app->request->header('Authorization'));
            if (! $token) {
                return null;
            }

            $userToken = UserToken::where('token', $token)->where('expires_at', '>', now())->first();
            if (! $userToken) {
                return null;
            }

            return $userToken->user;
        });

    }
}
