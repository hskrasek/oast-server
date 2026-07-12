<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'oast.sh' }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head><body>
<nav class="o-nav"><a class="o-wordmark" href="{{ route('app.home') }}">oast<em>.sh</em></a><div class="o-settings-nav">
<a href="{{ route('app.settings.account.show') }}">Account</a>@if(auth()->user()?->memberships()->exists())<a href="{{ route('app.settings.organization.show') }}">Organization</a><a href="{{ route('app.settings.tokens.index') }}">Tokens</a>@endif
<form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form></div></nav>
<main class="mx-auto max-w-[var(--container-page)] px-6 py-12">@if(session('status'))<div class="o-confirm-box">{{ session('status') }}</div>@endif<x-form-errors />{{ $slot }}</main></body></html>
