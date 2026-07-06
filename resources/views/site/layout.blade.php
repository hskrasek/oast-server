<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'oast — raw spec in, refined spec out')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
    <header>
        <nav class="o-nav">
            <a href="{{ route('home') }}" class="o-wordmark">oast<em>.sh</em></a>
            <div class="flex items-center gap-7">
                <a href="{{ route('reviews.index') }}" class="o-nav-link" @if (request()->routeIs('reviews.*')) aria-current="page" @endif>reviews</a>
                <a href="{{ route('home') }}#roadmap" class="o-nav-link">roadmap</a>
                <a href="{{ route('home') }}#notify" class="o-btn o-btn-outline h-auto py-2 px-3.5 text-mono-ui font-normal">notify me</a>
            </div>
        </nav>
    </header>

    <main class="flex-1">
        @yield('content')
    </main>

    <footer class="o-footer">
        <span>oast — raw spec in, refined spec out.</span>
        <a href="https://github.com">github ↗</a>
    </footer>
</body>
</html>
