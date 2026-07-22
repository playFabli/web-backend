<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AuthLoginRequest;
use App\Http\Requests\User\AuthRegisterRequest;
use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationCode;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceItemInventory;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserAvatarColor;
use App\Models\UserPrivacySetting;
use App\Models\UserToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(AuthRegisterRequest $request)
    {
        $data = $request->validated();

        // Verify reCAPTCHA
        // $recaptchaToken = $data['recaptcha_token'] ?? null;
        // if (!$recaptchaToken) {
        //     return response()->json(['message' => 'reCAPTCHA verification failed.'], 422);
        // }

        // $recaptchaResponse = Http::withoutVerifying()->post('https://www.google.com/recaptcha/api/siteverify', [
        //     'secret' => env('RECAPTCHA_SECRET_KEY', '6LeIxAcTAAAAAGG-vFI1NtQBDkgbRrm8k4W3hK3S'), // placeholder secret
        //     'response' => $recaptchaToken,
        // ]);

        // $recaptchaData = $recaptchaResponse->json();
        // if (!$recaptchaData['success'] ?? false) {
        //     return response()->json(['message' => 'reCAPTCHA verification failed.'], 422);
        // }

        $data['password'] = bcrypt($data['password']);

        $siteSetting = SiteSetting::find(1);
        $data['coins'] = $siteSetting ? $siteSetting->starting_currency : 100;

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'coins' => $data['coins'],
        ]);

        UserPrivacySetting::create([
            'user_id' => $user->id,
        ]);

        UserAvatarColor::create([
            'user_id' => $user->id,
        ]);

        $defaultAvatarPath = public_path('storage/avatars/avatar.png');
        $userAvatarPath = public_path("storage/avatars/{$user->id}.png");
        $userHeadshotPath = public_path("storage/headshots/{$user->id}.png");
        if (file_exists($defaultAvatarPath)) {
            copy($defaultAvatarPath, $userAvatarPath);
            if (! file_exists($userHeadshotPath)) {
                copy($defaultAvatarPath, $userHeadshotPath);
            }
        }

        $token = Str::random(60);
        $expiresAt = now()->addDays(7);

        UserToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        $code = new EmailVerificationCode();
        $code->user_id = $user->id;
        $code->code = bin2hex(random_bytes(32));
        $code->save();

        Mail::to([$user->email])->send(new EmailVerificationMail($code->code));

        return response()->json([
            'data' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(AuthLoginRequest $request)
    {
        $data = $request->validated();

        $field = 'username';
        if (str_contains($data['username'], '@')) {
            $field = 'email';
        }

        $user = User::where($field, $data['username'])->first();
        if (! $user) {
            return response()->json(['message' => 'Invalid username or password.', 'errors' => []], 422);
        }

        if (! password_verify($data['password'], $user['password'])) {
            return response()->json(['message' => 'Invalid username or password.', 'errors' => []], 422);
        }

        $token = Str::random(60);
        $expiresAt = now()->addDays(7);

        UserToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return response()->json(['token' => $token], 200);
    }
}
