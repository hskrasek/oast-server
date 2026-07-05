@extends('site.layout')

@section('title', 'Subscription Confirmed - oast')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-12">
    <h1 class="text-4xl font-bold mb-4">Subscription Confirmed</h1>
    <p class="text-lg">Subscription confirmed for {{ $email }}. See you at launch.</p>
</div>
@endsection
