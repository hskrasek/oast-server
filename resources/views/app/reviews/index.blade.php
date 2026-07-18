<x-app-layout title="Reviews — oast">
    <section class="o-app-page" x-data="{ ready: false }" x-init="$nextTick(() => ready = true)">
        <header class="o-page-head">
            <div><p class="o-label">organization review history</p><h1 class="o-headline">Reviews</h1></div>
            <a class="o-btn" href="{{ route('app.reviews.create') }}">Start a review</a>
        </header>

        <div class="o-state-card" x-show="!ready" aria-live="polite">Loading reviews…</div>
        <div x-show="ready" x-cloak>
            @if ($reviews->isEmpty())
                <div class="o-state-card">
                    <h2 class="o-title">No reviews yet</h2>
                    <p>Paste or upload an OpenAPI document to ask the Council for a review.</p>
                    <a class="o-btn" href="{{ route('app.reviews.create') }}">Start a review</a>
                </div>
            @else
                <div class="o-review-list">
                    @foreach ($reviews as $review)
                        @php
                            $cost = collect($review->metrics ?? [])->first(
                                fn ($metric) => is_array($metric) && array_key_exists('total_cost_usd', $metric),
                            );
                        @endphp
                        <div class="o-review-row">
                            <a href="{{ route('app.reviews.show', $review->id) }}">
                                <strong>{{ $review->spec_ref ?: 'Pasted specification' }}</strong>
                                <small>{{ $review->created_at->toDayDateTimeString() }}</small>
                            </a>
                            <span class="o-status o-status-{{ $review->status }}">{{ $statusLabels[$review->status] }}</span>
                            <span class="o-review-cost">{{ $cost === null ? '—' : '$'.number_format((float) $cost['total_cost_usd'], 4) }}</span>
                            @if ($review->status === 'error')
                                <span class="o-review-error">{{ $review->error ?: 'The review could not complete.' }}</span>
                            @endif
                            @can('delete', $review)
                                <form method="POST" action="{{ route('app.reviews.destroy', $review->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="o-btn o-btn-danger" type="submit" data-confirm="Delete this review and its retained specification permanently?">Delete</button>
                                </form>
                            @endcan
                        </div>
                    @endforeach
                </div>
                {{ $reviews->links() }}
            @endif
        </div>
    </section>
</x-app-layout>
