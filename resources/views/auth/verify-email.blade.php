<x-auth-layout title="Verify email"><h1 class="o-headline">Verify email</h1><p>Check your inbox, or request another link.</p>
<form method="POST" action="{{ route('verification.send') }}">@csrf<button class="o-btn" type="submit">Resend verification</button></form>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="o-btn o-btn-outline" type="submit">Sign out</button></form></x-auth-layout>
