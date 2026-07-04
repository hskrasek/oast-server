<?php

declare(strict_types=1);

use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewEventsController;
use App\Http\Controllers\ShowReviewController;
use Illuminate\Support\Facades\Route;

Route::domain(config()->string('oast.api_domain'))->group(function () {
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::get('/reviews/{review}', ShowReviewController::class)->name('reviews.show');
    Route::get('/reviews/{review}/events', ReviewEventsController::class)->name('reviews.events');
});
