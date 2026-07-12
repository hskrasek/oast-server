<x-auth-layout title="Sign in"><h1 class="o-headline">Sign in</h1><x-form-errors />
<form method="POST" action="{{ route('login') }}" class="o-form">@csrf
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required autofocus>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<button class="o-btn" type="submit">Sign in</button><a href="{{ route('password.request') }}">Forgot password?</a></form></x-auth-layout>
