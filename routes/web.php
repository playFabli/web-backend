<?php

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Models\UserPaymentContract;
use Illuminate\Support\Facades\Route;

Route::post("/payments/webhook", function() {
    $data = request()->all();

    if(request()->header("X-Api-Key") != env("WEBHOOK_API_KEY")) {
        return response()->json(["Invalid API key"], 403);
    }

    if($data["eventType"] === "payment.success") {
        $contract = UserPaymentContract::where("contract_uuid", $data["contractId"])->first();
        if(!$contract) {
            return response()->json(["Contract not found"], 404);
        } else {
            $availableProducts = ["5" => 500, "9.99" => 1000, "19.99" => 2500, "39.99" => 5000];
            $amountBought = $availableProducts[$data["amount"]];

            $user = User::find($contract->user_id);
            $user->coins = $user->coins + $amountBought;
            $user->save();

            $contract->delete();

            return response()->json(["All good"], 200);
        }
    }
});