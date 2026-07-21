<?php

use Illuminate\Support\Facades\Route;

Route::get('/test-lava-offers', function() {
    $response = Illuminate\Support\Facades\Http::withoutVerifying()->withHeaders([
        'X-Api-Key' => env('PAYMENT_API_KEY'),
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ])->get('https://gate.lava.top/api/v2/products', ["feedVisiblity"=>"ALL"]);

    return response()->json($response->json());
});