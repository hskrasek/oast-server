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

// OG raster templates — local render targets for screenshot capture, never in prod.
if (app()->environment('local')) {
    Route::view('/og/home', 'site.og-home')->name('og.home');
    Route::get('/og/{slug}', function (string $slug, App\Site\PublicationRepository $publications) {
        $publication = $publications->find($slug) ?? abort(404);
        $counts = $publication->findingCounts();

        return view('site.og', [
            'publication' => $publication,
            'cost' => $publication->totalCostUsd(),
            'tally' => array_filter([
                'text-sev-blocker' => $counts['blocker'] !== 0 ? $counts['blocker'] . ' blocker' . ($counts['blocker'] > 1 ? 's' : '') : null,
                'text-sev-should-fix' => $counts['should-fix'] !== 0 ? $counts['should-fix'] . ' should-fix' : null,
                'text-sev-consider' => $counts['consider'] !== 0 ? $counts['consider'] . ' consider' : null,
            ]),
        ]);
    })->name('og.review');
}

Route::post('/subscribe', SubscribeController::class)
    ->middleware('throttle:subscribe')->name('subscribe');
Route::get('/subscribe/confirm/{email}', ConfirmSubscriptionController::class)
    ->middleware('signed')->name('subscribe.confirm');
