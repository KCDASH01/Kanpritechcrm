@extends('layouts.admin')

@section('title', $organization->name . ' — CRM Admin')
@section('page-title', $organization->name)

@section('header-actions')
    <a href="{{ route('subscriptions.activate.form', $organization) }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        Activate Subscription
    </a>
    <form method="POST" action="{{ route('organizations.toggle', $organization) }}" class="inline">
        @csrf
        <button type="submit"
                class="text-sm font-medium px-4 py-2 rounded-lg border transition-colors
                       {{ $organization->is_active ? 'border-red-300 text-red-600 hover:bg-red-50' : 'border-green-300 text-green-600 hover:bg-green-50' }}">
            {{ $organization->is_active ? 'Deactivate' : 'Activate' }}
        </button>
    </form>
@endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left: Details --}}
    <div class="lg:col-span-1 space-y-5">
        {{-- Info card --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Organization Info</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Email</dt>
                    <dd class="text-gray-900 font-medium">{{ $organization->email ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="text-gray-900">{{ $organization->phone ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Timezone</dt>
                    <dd class="text-gray-900">{{ $organization->timezone }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $organization->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $organization->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Created</dt>
                    <dd class="text-gray-900">{{ $organization->created_at->format('M j, Y') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Owner card --}}
        @if ($organization->owner)
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <h2 class="font-semibold text-gray-900 mb-4">Owner</h2>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                        <span class="text-indigo-600 font-semibold">{{ strtoupper(substr($organization->owner->name, 0, 1)) }}</span>
                    </div>
                    <div>
                        <p class="font-medium text-gray-900 text-sm">{{ $organization->owner->name }}</p>
                        <p class="text-xs text-gray-400">{{ $organization->owner->email }}</p>
                        @if ($organization->owner->is_sso_user)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-violet-100 text-violet-700 mt-1">SSO</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Right: Subscriptions + Users --}}
    <div class="lg:col-span-2 space-y-5">
        {{-- Subscriptions --}}
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900">Subscriptions</h2>
                <a href="{{ route('subscriptions.activate.form', $organization) }}"
                   class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">+ Activate</a>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($organization->subscriptions as $sub)
                    <div class="px-6 py-4 flex items-center gap-4">
                        <div class="flex-1 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-900">{{ ucfirst($sub->plan) }}</span>
                                <span class="text-xs text-gray-400">({{ $sub->subscription_source }})</span>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs
                                    {{ $sub->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ $sub->status }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ $sub->start_date?->format('M j, Y') }} →
                                {{ $sub->end_date ? $sub->end_date->format('M j, Y') : 'No expiry' }}
                                @if ($sub->amount)
                                    · {{ $sub->currency }} {{ number_format($sub->amount, 2) }}
                                @endif
                            </p>
                        </div>
                        @if ($sub->is_active)
                            <form method="POST" action="{{ route('subscriptions.cancel', $sub) }}">
                                @csrf
                                <button type="submit"
                                        onclick="return confirm('Cancel this subscription?')"
                                        class="text-xs text-red-600 hover:text-red-700 font-medium">Cancel</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div class="px-6 py-8 text-center text-gray-400 text-sm">No subscriptions.</div>
                @endforelse
            </div>
        </div>

        {{-- Users --}}
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Users ({{ $organization->users->count() }})</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($organization->users as $user)
                    <div class="px-6 py-3 flex items-center gap-3 text-sm">
                        <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
                            <span class="text-gray-600 font-semibold text-xs">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('users.show', $user) }}"
                               class="font-medium text-gray-900 hover:text-indigo-600 truncate block">{{ $user->name }}</a>
                            <p class="text-xs text-gray-400">{{ $user->email }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-gray-500 capitalize">{{ $user->role }}</span>
                            @if ($user->is_sso_user)
                                <span class="text-xs bg-violet-100 text-violet-700 px-1.5 py-0.5 rounded">SSO</span>
                            @endif
                            <span class="w-2 h-2 rounded-full {{ $user->is_active ? 'bg-green-400' : 'bg-red-400' }}"></span>
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-8 text-center text-gray-400 text-sm">No users.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
