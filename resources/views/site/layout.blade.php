<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'oast')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="bg-gray-900 text-white">
        <nav class="max-w-6xl mx-auto px-4 py-4 flex justify-between items-center">
            <a href="{{ route('home') }}" class="text-2xl font-bold">oast</a>
            <a href="{{ route('reviews.index') }}" class="hover:text-gray-300">Reviews</a>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="bg-gray-900 text-white">
        <div class="max-w-6xl mx-auto px-4 py-6 text-center">
            <p class="text-sm">oast — raw spec in, refined spec out. <a href="https://github.com" class="hover:text-gray-300">GitHub</a></p>
        </div>
    </footer>
</body>
</html>
