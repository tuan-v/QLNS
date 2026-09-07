<?php

use App\Http\Controllers\Api\V1\AddressController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('addresses')->group(function (): void {
    Route::get('/provinces', [AddressController::class, 'provinces']);
    Route::get('/communes', [AddressController::class, 'communes']);
});
