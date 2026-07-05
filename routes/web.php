<?php

declare(strict_types=1);

use App\Http\Controllers\Site\ConfirmSubscriptionController;
use App\Http\Controllers\Site\SubscribeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/subscribe', SubscribeController::class)
    ->middleware('throttle:subscribe')->name('subscribe');
Route::get('/subscribe/confirm/{email}', ConfirmSubscriptionController::class)
    ->middleware('signed')->name('subscribe.confirm');
