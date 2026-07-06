@props(['confidence' => 'lone-flag', 'severity' => 'consider'])

@if ($confidence === 'split')
<span {{ $attributes->merge(['class' => 'o-conf o-conf-split']) }}>{{ $confidence }}</span>
@else
<span {{ $attributes->merge(['class' => 'o-conf o-conf-' . $confidence . ' o-sev-' . $severity]) }}><span class="o-conf-text">{{ $confidence }}</span></span>
@endif
