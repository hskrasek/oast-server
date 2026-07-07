<?php

declare(strict_types=1);

use App\Http\Controllers\Site\ConfirmSubscriptionController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\ReviewIndexController;
use App\Http\Controllers\Site\ReviewShowController;
use App\Http\Controllers\Site\SubscribeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::view('/why', 'site.why')->name('why');
Route::get('/reviews', ReviewIndexController::class)->name('reviews.index');
Route::get('/reviews/{slug}', ReviewShowController::class)->name('reviews.show');

Route::post('/subscribe', SubscribeController::class)
    ->middleware('throttle:subscribe')->name('subscribe');
Route::get('/subscribe/confirm/{email}', ConfirmSubscriptionController::class)
    ->middleware('signed')->name('subscribe.confirm');
