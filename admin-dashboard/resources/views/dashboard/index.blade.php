@extends('layouts.admin')

@section('title', 'Dashboard — CRM Admin')
@section('page-title', 'Dashboard')

@section('content')
{{-- Stats grid --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    @php
        $cards = [
            ['label' => 'Organizations',        'value' => $stats['total_organizations'],    'sub' => $stats['active_organizations'] . ' active',        'color' => 'indigo'],
            ['label' => 'Users',                 'value' => $stats['total_users'],            'sub' => $stats['sso_users'] . ' SSO users',                'color' => 'blue'],
            ['label' => 'Active Subscriptions',  'value' => $stats['total_subscriptions'],   'sub' => $stats['business_subscriptions'] . ' business',    'color' => 'emerald'],
            ['label' => 'New Orgs This Month',   'value' => $stats['new_orgs_this_month'],   'sub' => $stats['external_subscriptions'] . ' ext. subs',   'color' => 'violet'],
        ];
    @endphp

    @foreach ($cards as $card)
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-sm font-medium text-gray-500">{{ $card['label'] }}</p>
            <p class="text-3xl font-bold text-gray-900 mt-1">{{ number_format($card['value']) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ $card['sub'] }}</p>
        </div>
    @endforeach
</div>

{{-- Recent Organizations --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-semibold text-gray-900">Recent Organizations</h2>
        <a href="{{ route('organizations.index') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">View all →</a>
    </div>
    <div class="divide-y divide-gray-50">
        @forelse ($recentOrgs as $org)
            <div class="px-6 py-4 flex items-center gap-4">
                <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center shrink-0">
                    <span class="text-indigo-600 font-semibold text-sm">{{ strtoupper(substr($org->name, 0, 1)) }}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <a href="{{ route('organizations.show', $org) }}"
                       class="font-medium text-gray-900 hover:text-indigo-600 text-sm truncate block">{{ $org->name }}</a>
                    <p class="text-xs text-gray-400">{{ $org->owner?->email ?? $org->email }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @php $sub = $org->activeSubscription; @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ ($sub?->plan === 'business') ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">
                        {{ $sub ? ucfirst($sub->plan) : 'No Sub' }}
                    </span>
                    <span class="text-xs text-gray-400">{{ $org->created_at->diffForHumans() }}</span>
                </div>
            </div>
        @empty
            <div class="px-6 py-8 text-center text-gray-400 text-sm">No organizations yet.</div>
        @endforelse
    </div>
</div>
@endsection
