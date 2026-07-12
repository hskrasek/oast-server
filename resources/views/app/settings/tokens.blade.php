<x-auth-layout title="Access tokens"><h1 class="o-headline">Access tokens</h1><x-form-errors />
@if(is_string($plainToken))<div class="o-confirm-box"><code>{{ $plainToken }}</code><button type="button" data-copy="{{ $plainToken }}">Copy token</button></div>@endif
<form method="POST" action="{{ route('app.settings.tokens.store') }}" class="o-form">@csrf<label for="token_name">Name</label><input class="o-input" id="token_name" name="name" required>
<label for="expires_at">Expires at</label><input class="o-input" id="expires_at" name="expires_at" type="datetime-local"><button class="o-btn" type="submit">Create token</button></form>
@foreach($tokens as $token)<div><span>{{ $token->name }}</span><span>{{ implode(', ', $token->abilities ?? []) }}</span><span>{{ $token->last_used_at }}</span>
<form method="POST" action="{{ route('app.settings.tokens.destroy', $token) }}">@csrf @method('DELETE')<button data-confirm="Revoke this token?" type="submit">Revoke</button></form></div>@endforeach</x-auth-layout>
