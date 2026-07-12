<x-auth-layout title="Confirm password"><h1 class="o-headline">Confirm password</h1><x-form-errors />
<form method="POST" action="{{ route('password.confirm') }}" class="o-form">@csrf
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<button class="o-btn" type="submit">Confirm</button></form></x-auth-layout>
