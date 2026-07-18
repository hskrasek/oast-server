<x-auth-layout title="Create installation"><h1 class="o-headline">Create installation</h1><x-form-errors />
<form method="POST" action="{{ route('setup.store') }}" class="o-form">@csrf
<label for="name">Name</label><input class="o-input" id="name" name="name" required>
<label for="email">Email</label><input class="o-input" id="email" name="email" type="email" required>
<label for="organization_name">Organization</label><input class="o-input" id="organization_name" name="organization_name" required>
<label for="password">Password</label><input class="o-input" id="password" name="password" type="password" required>
<label for="password_confirmation">Confirm password</label><input class="o-input" id="password_confirmation" name="password_confirmation" type="password" required>
<button class="o-btn" type="submit">Create installation</button></form></x-auth-layout>
