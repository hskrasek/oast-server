<x-auth-layout title="Choose password"><h1 class="o-headline">Choose password</h1><x-form-errors />
<form method="POST" action="{{ route('password.update') }}" class="o-form">@csrf
<input type="hidden" name="token" value="{{ $request->route('token') }}">
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" value="{{ $request->email }}" required>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required>
<button class="o-btn" type="submit">Reset password</button></form></x-auth-layout>
