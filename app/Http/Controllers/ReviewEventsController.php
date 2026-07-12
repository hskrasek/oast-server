<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PersonalAccessToken;
use App\Models\ReviewEvent;
use App\Organizations\OrganizationContext;
use App\Reviews\ScopedReviewResolver;
use App\Streaming\StreamLeaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReviewEventsController
{
    private const array TERMINAL = ['review.completed', 'review.failed'];

    public function __invoke(
        Request $request,
        string $review,
        ScopedReviewResolver $resolver,
        OrganizationContext $context,
        StreamLeaseManager $leases,
    ): StreamedResponse {
        $model = $resolver->findOrFail($review);
        Gate::authorize('follow', $model);
        $lease = $leases->acquire($this->principal($context));
        $lastId = (int) ($request->headers->get('Last-Event-ID') ?? $request->query('lastEventId', '0'));

        return response()->stream(function () use ($model, $lastId, $context, $lease): void {
            // A review runs for minutes; the default max_execution_time (30s)
            // would fatal mid-stream and force clients into reconnect churn.
            set_time_limit(0);
            $cursor = $lastId;
            try {
                while (true) {
                    if (! $context->stillAuthorized($model)) {
                        return;
                    }

                    $lease->refresh();
                    foreach ($model->events()->where('id', '>', $cursor)->orderBy('id')->get() as $event) {
                        $this->emit($event);
                        $cursor = $event->id;
                        if (in_array($event->event, self::TERMINAL, true)) {
                            return;
                        }
                    }

                    if (connection_aborted() === 1) {
                        return;
                    }

                    Sleep::for(500)->milliseconds();
                }
            } finally {
                $lease->release();
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function principal(OrganizationContext $context): string
    {
        $token = $context->token();
        if ($token instanceof PersonalAccessToken) {
            return 'token:' . $token->id;
        }

        return 'user:' . $context->membership()->user_id;
    }

    private function emit(ReviewEvent $event): void
    {
        echo sprintf('id: %s%s', $event->id, PHP_EOL);
        echo sprintf('event: %s%s', $event->event, PHP_EOL);
        echo sprintf('data: %s%s%s', json_encode($event->data), PHP_EOL, PHP_EOL);

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
