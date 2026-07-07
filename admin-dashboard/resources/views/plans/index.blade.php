@extends('layouts.admin')

@section('title', 'Subscription Plans — CRM Admin')
@section('page-title', 'Subscription Plans')

@section('header-actions')
    <a href="{{ route('plans.create') }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        + New Plan
    </a>
@endsection

@section('content')

{{-- Plan cards --}}
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-10">
    @foreach ($plans as $plan)
        @php $activeCount = $plan->subscriptionsCount(); @endphp
        <div class="relative bg-white rounded-2xl border {{ $plan->is_featured ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-gray-200' }} overflow-hidden flex flex-col">

            {{-- Featured badge --}}
            @if ($plan->is_featured)
                <div class="absolute top-4 right-4">
                    <span class="bg-indigo-600 text-white text-xs font-semibold px-2.5 py-1 rounded-full">Popular</span>
                </div>
            @endif

            {{-- Header --}}
            <div class="p-6 pb-4 border-b border-gray-100">
                <div class="flex items-center gap-2 mb-1">
                    <h2 class="text-lg font-bold text-gray-900">{{ $plan->name }}</h2>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium
                        {{ $plan->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $plan->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>
                <p class="text-sm text-gray-500">{{ $plan->description }}</p>

                {{-- Pricing --}}
                <div class="mt-4 flex items-end gap-4">
                    <div>
                        <p class="text-xs text-gray-400 font-medium uppercase tracking-wide">Monthly</p>
                        <p class="text-2xl font-bold text-gray-900">
                            {{ $plan->monthly_price > 0 ? '₹' . number_format($plan->monthly_price) : 'Free' }}
                        </p>
                    </div>
                    @if ($plan->yearly_price > 0)
                        <div class="pb-0.5">
                            <p class="text-xs text-gray-400 font-medium uppercase tracking-wide">Yearly</p>
                            <p class="text-lg font-semibold text-indigo-600">₹{{ number_format($plan->yearly_price) }}</p>
                        </div>
                    @endif
                </div>

                {{-- Active orgs --}}
                <p class="mt-3 text-xs text-gray-400">
                    <span class="font-semibold text-gray-700">{{ $activeCount }}</span> active organization{{ $activeCount !== 1 ? 's' : '' }}
                </p>
            </div>

            {{-- Limits --}}
            <div class="px-6 py-4 border-b border-gray-100">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Limits</p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    @php
                        $limits = [
                            'Leads'       => $plan->leads_limit,
                            'Deals'       => $plan->deals_limit,
                            'Pipelines'   => $plan->pipelines_limit,
                            'Team Members'=> $plan->team_members_limit,
                            'Departments' => $plan->departments_limit,
                        ];
                    @endphp
                    @foreach ($limits as $label => $value)
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">{{ $label }}</span>
                            <span class="font-semibold {{ $value === -1 ? 'text-indigo-600' : 'text-gray-800' }}">
                                {{ $plan->limitLabel($value) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Feature flags --}}
            <div class="px-6 py-4 border-b border-gray-100">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Features</p>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    @php
                        $flags = [
                            'Bulk Import'     => $plan->bulk_import,
                            'SSO Access'      => $plan->sso_access,
                            'API Access'      => $plan->api_access,
                            'Adv. Reports'    => $plan->advanced_reports,
                            'Priority Support'=> $plan->priority_support,
                            'Custom Pipelines'=> $plan->custom_pipelines,
                        ];
                    @endphp
                    @foreach ($flags as $label => $enabled)
                        <div class="flex items-center gap-1.5">
                            @if ($enabled)
                                <svg class="w-4 h-4 text-green-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                            @else
                                <svg class="w-4 h-4 text-gray-300 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            @endif
                            <span class="{{ $enabled ? 'text-gray-700' : 'text-gray-400' }}">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Feature list --}}
            @if ($plan->feature_list)
                <div class="px-6 py-4 border-b border-gray-100">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">What's included</p>
                    <ul class="space-y-1.5">
                        @foreach ($plan->feature_list as $item)
                            <li class="flex items-start gap-2 text-sm text-gray-600">
                                <svg class="w-4 h-4 text-indigo-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Actions --}}
            <div class="px-6 py-4 flex items-center gap-3 mt-auto">
                <a href="{{ route('plans.edit', $plan) }}"
                   class="flex-1 text-center bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-sm font-semibold py-2 rounded-xl transition-colors">
                    Edit
                </a>
                <form method="POST" action="{{ route('plans.toggle', $plan) }}" class="flex-1">
                    @csrf
                    <button type="submit"
                            class="w-full text-sm font-semibold py-2 rounded-xl transition-colors
                                   {{ $plan->is_active
                                       ? 'bg-red-50 hover:bg-red-100 text-red-600'
                                       : 'bg-green-50 hover:bg-green-100 text-green-700' }}">
                        {{ $plan->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </form>
            </div>
        </div>
    @endforeach
</div>

@endsection
