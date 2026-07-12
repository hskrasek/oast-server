<?php

declare(strict_types=1);

use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewEventsController;
use App\Http\Controllers\ShowReviewController;
use Illuminate\Support\Facades\Route;

Route::domain(config()->string('oast.api_domain'))->middleware('auth:sanctum')->group(function (): void {
    Route::post('/reviews', [ReviewController::class, 'store'])->middleware('abilities:review:create')->name('api.reviews.store');
    Route::get('/reviews/{review}', ShowReviewController::class)->middleware('abilities:review:read')->name('api.reviews.show');
    Route::get('/reviews/{review}/events', ReviewEventsController::class)->middleware('abilities:review:follow')->name('api.reviews.events');
});
