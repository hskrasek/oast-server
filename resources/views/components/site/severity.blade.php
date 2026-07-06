@props(['severity' => 'consider'])

<span {{ $attributes->merge(['class' => 'o-sev o-sev-' . $severity]) }}>{{ $severity }}</span>
