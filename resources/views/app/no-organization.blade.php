<x-app-layout title="No organization"><h1 class="o-headline">You are not a member of any organization</h1>
<p>Ask an owner to invite this email address.</p>
<p><a href="{{ route('app.settings.account.show') }}">Manage account</a></p>
<form method="POST" action="{{ route('logout') }}">@csrf
<button class="o-btn" type="submit">Sign out</button></form></x-app-layout>
