@extends('layouts.app')

@section('title', __('ui.users.title'))

@section('content')
<h1>{{ __('ui.users.title') }}</h1>

<x-card>
    <table>
        <thead><tr>
            <th>{{ __('ui.users.name') }}</th>
            <th>{{ __('ui.users.email') }}</th>
            <th>{{ __('ui.users.role') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse ($users as $user)
            <tr>
                <td>{{ $user->name }}</td>
                <td><x-ltr>{{ $user->email }}</x-ltr></td>
                <td>{{ __('ui.users.role_' . $user->role) }}</td>
                <td style="white-space:nowrap;">
                    <form method="POST" action="{{ route('users.update-role', $user) }}" style="display:flex;gap:0.4rem;align-items:center;">
                        @csrf
                        <select name="role">
                            <option value="admin" {{ $user->role === 'admin' ? 'selected' : '' }}>{{ __('ui.users.role_admin') }}</option>
                            <option value="operator" {{ $user->role === 'operator' ? 'selected' : '' }}>{{ __('ui.users.role_operator') }}</option>
                            <option value="developer" {{ $user->role === 'developer' ? 'selected' : '' }}>{{ __('ui.users.role_developer') }}</option>
                            <option value="viewer" {{ $user->role === 'viewer' ? 'selected' : '' }}>{{ __('ui.users.role_viewer') }}</option>
                        </select>
                        <button type="submit" class="btn btn-small">{{ __('ui.users.change_role_button') }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state>{{ __('ui.users.no_users') }}</x-empty-state></td></tr>
        @endforelse
        </tbody>
    </table>
</x-card>

<x-card :title="__('ui.users.roles_explained_title')">
    <table>
        <tr><th>{{ __('ui.users.role_admin') }}</th><td>{{ __('ui.users.role_admin_description') }}</td></tr>
        <tr><th>{{ __('ui.users.role_operator') }}</th><td>{{ __('ui.users.role_operator_description') }}</td></tr>
        <tr><th>{{ __('ui.users.role_developer') }}</th><td>{{ __('ui.users.role_developer_description') }}</td></tr>
        <tr><th>{{ __('ui.users.role_viewer') }}</th><td>{{ __('ui.users.role_viewer_description') }}</td></tr>
    </table>
</x-card>
@endsection
