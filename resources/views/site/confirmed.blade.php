@extends('site.layout')

@section('title', 'Subscription Confirmed - oast')

@section('content')
<div class="mx-auto max-w-[880px] px-6 py-24 flex flex-col gap-6">
    <h1 class="o-headline">Subscription confirmed</h1>
    <div class="o-confirm-box max-w-[460px]">
        <span class="font-semibold">→</span>
        <span>Subscription confirmed for {{ $email }}. See you at launch.</span>
    </div>
    <p><a href="{{ route('reviews.index') }}" class="o-mono-ui" style="color: var(--text-2)">read the published reviews →</a></p>
</div>
@endsection
