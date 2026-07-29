<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'oast.sh' }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body><nav class="o-nav"><a class="o-wordmark" href="{{ route('home') }}">oast<em>.sh</em></a></nav>
<main class="mx-auto max-w-lg px-6 py-16">{{ $slot }}</main></body></html>
