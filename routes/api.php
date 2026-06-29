<?php

declare(strict_types=1);

use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::domain(config()->string('oast.api_domain'))->group(function () {
    Route::post('/reviews', [ReviewController::class, 'store']);
});
