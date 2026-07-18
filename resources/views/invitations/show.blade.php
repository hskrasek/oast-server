<x-auth-layout title="Organization invitation"><meta name="referrer" content="no-referrer"><h1 class="o-headline">Organization invitation</h1>
@auth<form method="POST" action="{{ route('invitations.accept', $token) }}">@csrf<button class="o-btn" type="submit">Accept invitation</button></form>
@else<form method="POST" action="{{ route('invitations.start-registration', $token) }}">@csrf<button class="o-btn" type="submit">Register to accept</button></form>
<form method="POST" action="{{ route('invitations.start-login', $token) }}">@csrf<button class="o-btn o-btn-outline" type="submit">Sign in with the invited email</button></form>@endauth</x-auth-layout>
