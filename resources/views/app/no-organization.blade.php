<x-auth-layout title="No organization"><h1 class="o-headline">You are not a member of any organization</h1>
<p>Ask an owner to invite this email address.</p><form method="POST" action="{{ route('logout') }}">@csrf
<button class="o-btn" type="submit">Sign out</button></form></x-auth-layout>
