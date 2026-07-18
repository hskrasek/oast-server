<x-app-layout title="Organization settings"><h1 class="o-headline">Organization settings</h1>
@can('update', $organization)
<form method="POST" action="{{ route('app.settings.organization.update') }}" class="o-form">@csrf @method('PATCH')
<label for="name">Organization name</label><input class="o-input" id="name" name="name" value="{{ $organization->name }}" required><button class="o-btn" type="submit">Save</button></form>
@endcan
<h2 class="o-title">Members</h2><table class="o-table-management"><tbody>@foreach($organization->memberships as $membership)<tr><td>{{ $membership->user->email }}</td><td>{{ $membership->role->value }}</td><td>
@can('delete', $membership)
<form method="POST" action="{{ route('app.settings.organization.members.destroy', $membership) }}">@csrf @method('DELETE')<button data-confirm="Remove this member?" type="submit">Remove</button></form>
@if($membership->user_id !== auth()->id())<form method="POST" action="{{ route('app.settings.organization.ownership.transfer') }}">@csrf<input type="hidden" name="membership_id" value="{{ $membership->id }}"><button type="submit">Transfer ownership</button></form>@endif
@endcan
</td></tr>@endforeach</tbody></table>
<h2 class="o-title">Invitations</h2>@if(session('invitation_url'))<button type="button" data-copy="{{ session('invitation_url') }}">Copy invitation link</button>@endif
@can('create', [App\Models\OrganizationInvitation::class, $organization])
<form method="POST" action="{{ route('app.settings.organization.invitations.store') }}">@csrf<label for="invite_email">Email</label><input class="o-input" id="invite_email" name="email" type="email" required><button class="o-btn" type="submit">Invite</button></form>
@foreach($organization->invitations as $invitation)<form method="POST" action="{{ route('app.settings.organization.invitations.destroy', $invitation) }}">@csrf @method('DELETE')<span>{{ $invitation->email }}</span><button type="submit">Revoke</button></form>@endforeach
@endcan
</x-app-layout>
