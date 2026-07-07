@extends('layouts.admin')

@section('title', $user->name . ' — CRM Admin')
@section('page-title', $user->name)

@section('header-actions')
    <form method="POST" action="{{ route('users.toggle', $user) }}" class="inline">
        @csrf
        <button type="submit"
                class="text-sm font-medium px-4 py-2 rounded-lg border transition-colors
                       {{ $user->is_active ? 'border-red-300 text-red-600 hover:bg-red-50' : 'border-green-300 text-green-600 hover:bg-green-50' }}">
            {{ $user->is_active ? 'Deactivate User' : 'Activate User' }}
        </button>
    </form>
@endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- User profile card --}}
    <div class="lg:col-span-1">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-14 h-14 rounded-full bg-indigo-100 flex items-center justify-center">
                    <span class="text-indigo-600 font-bold text-xl">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-900">{{ $user->name }}</h2>
                    <p class="text-sm text-gray-400">{{ $user->email }}</p>
                </div>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Role</dt>
                    <dd class="capitalize text-gray-900 font-medium">{{ $user->role }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Type</dt>
                    <dd>
                        @if ($user->is_sso_user)
                            <span class="bg-violet-100 text-violet-700 px-2 py-0.5 rounded-full text-xs font-medium">SSO — {{ $user->sso_provider }}</span>
                        @else
                            <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs font-medium">Direct</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="text-gray-900">{{ $user->phone ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Joined</dt>
                    <dd class="text-gray-900">{{ $user->created_at->format('M j, Y') }}</dd>
                </div>
                @if ($user->external_id)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">External ID</dt>
                        <dd class="text-gray-700 font-mono text-xs">{{ $user->external_id }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    {{-- Organization & Subscriptions --}}
    <div class="lg:col-span-2 space-y-5">
        @if ($user->organization)
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <h2 class="font-semibold text-gray-900 mb-3">Organization</h2>
                <div class="flex items-center justify-between">
                    <div>
                        <a href="{{ route('organizations.show', $user->organization) }}"
                           class="font-medium text-indigo-600 hover:text-indigo-700">{{ $user->organization->name }}</a>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $user->organization->email }}</p>
                    </div>
                    <a href="{{ route('organizations.show', $user->organization) }}"
                       class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">View org →</a>
                </div>
            </div>

            {{-- Subscription history --}}
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900">Subscription History</h2>
                    <a href="{{ route('subscriptions.activate.form', $user->organization) }}"
                       class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">+ Activate</a>
                </div>
                <div class="divide-y divide-gray-50">
                    @forelse ($user->organization->subscriptions as $sub)
                        <div class="px-6 py-4 flex items-center gap-4">
                            <div class="flex-1 text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ ucfirst($sub->plan) }}</span>
                                    <span class="text-xs text-gray-400">({{ $sub->subscription_source }})</span>
                                    <span class="text-xs px-1.5 py-0.5 rounded
                                        {{ $sub->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $sub->status }}
                                    </span>
                                </div>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ $sub->start_date?->format('M j, Y') }} — {{ $sub->end_date ? $sub->end_date->format('M j, Y') : 'No expiry' }}
                                    @if ($sub->amount)
                                        · {{ $sub->currency }} {{ number_format($sub->amount, 2) }}
                                    @endif
                                </p>
                            </div>
                            @if ($sub->is_active)
                                <form method="POST" action="{{ route('subscriptions.cancel', $sub) }}">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Cancel?')"
                                            class="text-xs text-red-600 hover:text-red-700 font-medium">Cancel</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <div class="px-6 py-8 text-center text-gray-400 text-sm">No subscriptions.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
