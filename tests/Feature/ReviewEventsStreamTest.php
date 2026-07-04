<?php

declare(strict_types=1);

// The polling branch (connection still open -> sleep -> loop again) needs a
// stub for the real `connection_aborted()` built-in, which always reports "0"
// under the CLI SAPI PHPUnit runs under. PHP resolves an unqualified function
// call from the innermost namespace first, so redeclaring it inside
// App\Http\Controllers shadows the built-in for ReviewEventsController without
// touching production code. The bracketed namespace syntax is required so this
// declaration and the rest of the test file (global namespace) can share one
// file.

namespace App\Http\Controllers {
    function connection_aborted(): int
    {
        static $calls = 0;

        // First check: still "connected" so the loop sleeps and polls again.
        // Second check: "disconnected" so the loop returns.
        return ++$calls >= 2 ? 1 : 0;
    }
}

namespace {
    use App\Models\Review;
    use Carbon\CarbonInterval;
    use Illuminate\Support\Sleep;

    beforeEach(fn() => config(['oast.api_domain' => 'api.oast.test']));

    it('streams stored events as SSE frames and closes on terminal', function (): void {
        $review = Review::factory()->create(['status' => 'complete']);
        $review->appendEvent('review.queued', ['mode' => 'council']);
        $review->appendEvent('review.completed', ['findings' => [], 'total_cost_usd' => 0.1]);

        $response = $this->get("https://{$this->apiHost()}/reviews/{$review->id}/events");

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $body = $response->streamedContent();

        expect($body)->toContain("event: review.queued\n")
            ->and($body)->toContain('"total_cost_usd":0.1')
            ->and($body)->toMatch('/id: \d+\nevent: review\.completed/');
    });

    it('replays only events after Last-Event-ID', function (): void {
        $review = Review::factory()->create(['status' => 'complete']);
        $first = $review->appendEvent('review.queued', []);
        $review->appendEvent('review.completed', ['findings' => []]);

        $body = $this->withHeader('Last-Event-ID', (string) $first->id)
            ->get("https://{$this->apiHost()}/reviews/{$review->id}/events")
            ->streamedContent();

        expect($body)->not->toContain('review.queued')
            ->and($body)->toContain('review.completed');
    });

    it('404s an unknown review as problem+json', function (): void {
        $this->get("https://{$this->apiHost()}/reviews/999/events")
            ->assertNotFound();
    });

    it('polls while the connection is open, then stops once it drops', function (): void {
        Sleep::fake();
        $review = Review::factory()->create(['status' => 'running']);
        $review->appendEvent('review.queued', ['mode' => 'council']);

        $body = $this->get("https://{$this->apiHost()}/reviews/{$review->id}/events")
            ->streamedContent();

        expect($body)->toContain('review.queued');
        Sleep::assertSlept(fn(CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 500);
    });
}
