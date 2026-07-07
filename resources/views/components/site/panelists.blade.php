@props(['models' => []])

@php
    $dotColors = ['bg-ember', 'bg-amber', 'bg-copper'];
@endphp

<span {{ $attributes->merge(['class' => 'flex flex-wrap gap-x-3.5 gap-y-1.5']) }}>
    @foreach ($models as $model)
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap [overflow-wrap:normal] [word-break:keep-all]" title="{{ $model }}">
        <span class="inline-block size-[6px] rounded-full {{ $dotColors[$loop->index % 3] }}" aria-hidden="true"></span>
        <span>{{ \App\Site\ModelDisplay::short($model) }}</span>
    </span>
    @endforeach
</span>
