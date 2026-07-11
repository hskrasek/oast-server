@props([
    'title' => 'oast — raw spec in, refined spec out',
    'ogTitle' => 'oast — raw spec in, refined spec out',
    'metaDescription' => null,
    'ogImage' => null,
])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Pre-launch: delete at launch along with the robots.txt Disallow --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="description" content="{{ $metaDescription ?? 'Multi-model API design review: three frontier models critique your OpenAPI spec independently; a judge organizes findings by severity and confidence.' }}">
    <meta property="og:site_name" content="oast.sh">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $metaDescription ?? 'Your API design, argued over by a panel that never gets tired.' }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $ogImage ?? url(\App\Site\Og\OgTemplate::homeImageUrl()) }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
    <header>
        <nav class="o-nav">
            <a href="{{ route('home') }}" class="o-wordmark">oast<em>.sh</em></a>
            <div class="flex items-center gap-7">
                <a href="{{ route('reviews.index') }}" class="o-nav-link" @if (request()->routeIs('reviews.*')) aria-current="page" @endif>reviews</a>
                <a href="{{ route('why') }}" class="o-nav-link" @if (request()->routeIs('why')) aria-current="page" @endif>why</a>
                <a href="{{ route('home') }}#roadmap" class="o-nav-link">roadmap</a>
                <a href="{{ route('home') }}#notify" class="o-btn o-btn-outline h-auto py-2 px-3.5 text-mono-ui font-normal">notify me</a>
            </div>
        </nav>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="o-footer">
        <span>oast — raw spec in, refined spec out.</span>
        <a href="https://github.com/hskrasek/oast-server">github ↗</a>
    </footer>
</body>
</html>
