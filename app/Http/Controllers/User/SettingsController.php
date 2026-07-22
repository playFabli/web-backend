<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    public function updateDescription(Request $request)
    {
        $user = app('token_user');

        $data = $request->only(['description']);

        $validator = Validator::make($data, [
            'description' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $user->description = $data['description'] ?? null;
        $user->save();

        return response()->json(['success' => true, 'description' => $user->description]);
    }

    public function changeEmail(Request $request)
    {
        $user = app('token_user');

        $data = $request->only(['email']);

        $validator = Validator::make($data, [
            'email' => 'required|email|unique:users,email,'.$user->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $user->email = $data['email'];
        $user->save();

        return response()->json(['success' => true, 'email' => $user->email]);
    }

    public function changePassword(Request $request)
    {
        $user = app('token_user');

        $data = $request->only(['current_password', 'password']);

        $validator = Validator::make($data, [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 403);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        return response()->json(['success' => true]);
    }

    public function changeUsername(Request $request)
    {
        $user = app('token_user');

        $data = $request->only(['username']);

        $validator = Validator::make($data, [
            'username' => 'required|string|min:3|max:50|alpha_dash|unique:users,username,'.$user->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if ($user->coins < 500) {
            return response()->json(['message' => 'Insufficient coins to change username.'], 403);
        }

        $user->username = $data['username'];
        $user->coins = $user->coins - 500;
        $user->save();

        return response()->json(['success' => true, 'username' => $user->username]);
    }

    public function updatePrivacySettings(Request $request)
    {
        $user = app('token_user');

        $data = $request->only([
            'profile_visible',
            'show_last_online_time',
            'show_rap',
            'who_can_post_on_wall',
            'who_can_see_inventory',
            'who_can_trade',
        ]);

        $validator = Validator::make($data, [
            'profile_visible' => 'boolean',
            'show_last_online_time' => 'boolean',
            'show_rap' => 'boolean',
            'who_can_post_on_wall' => 'integer|in:0,1,2',
            'who_can_see_inventory' => 'integer|in:0,1,2',
            'who_can_trade' => 'integer|in:0,1,2',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (isset($data['profile_visible'])) {
            $user->privacy->profile_visible = $data['profile_visible'];
        }
        if (isset($data['show_last_online_time'])) {
            $user->privacy->show_last_online_time = $data['show_last_online_time'];
        }
        if (isset($data['show_rap'])) {
            $user->privacy->show_rap = $data['show_rap'];
        }
        if (isset($data['who_can_post_on_wall'])) {
            $user->privacy->who_can_post_on_wall = $data['who_can_post_on_wall'];
        }
        if (isset($data['who_can_see_inventory'])) {
            $user->privacy->who_can_see_inventory = $data['who_can_see_inventory'];
        }
        if (isset($data['who_can_trade'])) {
            $user->privacy->who_can_trade = $data['who_can_trade'];
        }

        $user->privacy->save();

        return response()->json(['success' => true, 'privacy_settings' => $user->only([
            'profile_visible',
            'show_last_online_time',
            'show_rap',
            'who_can_post_on_wall',
            'who_can_see_inventory',
            'who_can_trade',
        ])]);
    }
}
