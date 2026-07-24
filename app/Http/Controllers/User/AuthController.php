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
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserAvatarColor;
use App\Models\UserPrivacySetting;
use App\Models\UserToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Request;

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

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json(['message' => 'If that email exists, a reset link has been sent.'], 200);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(1);

        PasswordResetToken::create([
            'email' => $user->email,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        Mail::to($user->email)->send(new PasswordResetMail($token, $user->email));

        return response()->json(['message' => 'If that email exists, a reset link has been sent.'], 200);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $reset = PasswordResetToken::where('token', $data['token'])->first();
        if (!$reset || $reset->expires_at->isPast()) {
            return response()->json(['message' => 'Invalid or expired token.'], 422);
        }

        $user = User::where('email', $reset->email)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->password = bcrypt($data['password']);
        $user->save();

        $reset->delete();

        return response()->json(['message' => 'Password reset successfully.'], 200);
    }
}
