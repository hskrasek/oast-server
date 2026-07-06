@extends('site.layout')

@section('title', 'Reviews - oast')

@section('content')
<div class="mx-auto max-w-[880px] px-6 py-16 flex flex-col gap-8">
    <header class="flex flex-col gap-3">
        <h1 class="o-headline">Published Reviews</h1>
        <p class="o-mono-small">real Council output · real specs · real costs</p>
    </header>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($publications as $publication)
        <x-site.review-card :publication="$publication" :show-date="true" />
        @endforeach
    </div>
</div>
@endsection
