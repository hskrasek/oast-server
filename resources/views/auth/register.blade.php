<x-auth-layout title="Accept invitation"><h1 class="o-headline">Accept invitation</h1><x-form-errors />
<form method="POST" action="{{ route('register') }}" class="o-form">@csrf<input type="hidden" name="invitation_token" value="{{ $token }}">
<label for="name">Name</label><input class="o-input" id="name" name="name" required>
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required>
<button class="o-btn" type="submit">Create account</button></form></x-auth-layout>
