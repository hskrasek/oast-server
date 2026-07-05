@extends('site.layout')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-12">
    <!-- Hero -->
    <section class="mb-16">
        <h1 class="text-5xl font-bold mb-4">Your API design, argued over by a panel that never gets tired.</h1>
    </section>

    <!-- Problem -->
    <section class="mb-16">
        <h2 class="text-3xl font-bold mb-4">The Problem</h2>
        <p class="text-lg">Spectral tells you an operationId is missing. Nobody tells you your resource model leaks the database, your payment flow can't be retried safely, or your 'REST' API is RPC in a trench coat — until clients depend on it.</p>
    </section>

    <!-- How It Works -->
    <section class="mb-16">
        <h2 class="text-3xl font-bold mb-4">How It Works</h2>
        <ol class="text-lg space-y-4">
            <li><strong>1.</strong> Three frontier models critique your spec independently — no shared rubric, no groupthink.</li>
            <li><strong>2.</strong> A judge model organizes their critiques into findings — it never adds its own.</li>
            <li><strong>3.</strong> Every finding carries severity (blocker / should-fix / consider) and confidence (consensus / majority / split / lone-flag).</li>
        </ol>
    </section>

    <!-- Split Explainer -->
    <section class="mb-16">
        <h2 class="text-3xl font-bold mb-4">When the Panel Disagrees</h2>
        <p class="text-lg">When the panel disagrees, you see both sides. A split on a blocker is the most valuable thing we can show you.</p>
    </section>

    <!-- Featured Reviews -->
    @if ($featured)
    <section class="mb-16">
        <h2 class="text-3xl font-bold mb-8">Featured Reviews</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach ($featured as $publication)
            <a href="{{ route('reviews.show', $publication->slug) }}" class="border border-gray-300 p-6 rounded hover:shadow-lg transition">
                <h3 class="text-xl font-bold mb-2">{{ $publication->specName }}</h3>
                <p class="text-gray-600 mb-2">{{ $publication->headline }}</p>
                <p class="text-sm text-gray-500">{{ $publication->dimension }}</p>
            </a>
            @endforeach
        </div>
    </section>
    @endif

    <!-- Signup -->
    <section class="mb-16">
        <h2 class="text-3xl font-bold mb-4">Get Notified at Launch</h2>
        <p class="text-lg mb-4">We're building in the open. Leave an email, get the launch.</p>
        <form method="POST" action="{{ route('subscribe') }}" class="max-w-md">
            @csrf
            <div class="mb-4">
                <input type="email" name="email" required placeholder="you@company.com" class="w-full px-4 py-2 border border-gray-300 rounded">
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
            </div>
            <button type="submit" class="px-6 py-2 bg-gray-900 text-white rounded hover:bg-gray-800">Notify me</button>
            @if (session('status'))
            <p role="status" class="text-green-600 mt-2">{{ session('status') }}</p>
            @endif
            @error('email')
            <p role="alert" class="text-red-600 mt-2">{{ $message }}</p>
            @enderror
        </form>
    </section>
</div>
@endsection
