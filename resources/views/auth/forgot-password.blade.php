<x-auth-layout title="Reset password"><h1 class="o-headline">Reset password</h1><x-form-errors />
<form method="POST" action="{{ route('password.email') }}" class="o-form">@csrf
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required>
<button class="o-btn" type="submit">Send reset link</button></form></x-auth-layout>
