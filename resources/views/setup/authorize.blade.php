<x-auth-layout title="Authorize setup"><h1 class="o-headline">Authorize setup</h1><x-form-errors />
<form method="POST" action="{{ route('setup.authorize') }}" class="o-form">@csrf
<label for="bootstrap_secret">Bootstrap secret</label><input class="o-input" id="bootstrap_secret" name="bootstrap_secret" type="password" required>
<button class="o-btn" type="submit">Continue</button></form></x-auth-layout>
