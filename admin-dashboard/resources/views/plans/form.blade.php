@extends('layouts.admin')

@section('title', ($plan->exists ? 'Edit Plan' : 'New Plan') . ' — CRM Admin')
@section('page-title', $plan->exists ? 'Edit Plan: ' . $plan->name : 'New Plan')

@section('header-actions')
    <a href="{{ route('plans.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700 font-medium transition-colors">
        ← Back to Plans
    </a>
@endsection

@section('content')

<form method="POST"
      action="{{ $plan->exists ? route('plans.update', $plan) : route('plans.store') }}"
      class="max-w-4xl mx-auto space-y-8">
    @csrf
    @if ($plan->exists)
        @method('PUT')
    @endif

    {{-- Validation errors --}}
    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 rounded-xl px-5 py-4 text-sm text-red-700">
            <p class="font-semibold mb-1">Please fix the following errors:</p>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Basic Info ─────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Basic Info</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            {{-- Name --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Plan Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $plan->name) }}"
                       placeholder="e.g. Business"
                       class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </div>

            {{-- Slug --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Slug <span class="text-red-500">*</span></label>
                <input type="text" name="slug" value="{{ old('slug', $plan->slug) }}"
                       placeholder="e.g. business"
                       class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <p class="text-xs text-gray-400 mt-1">Lowercase letters, numbers, hyphens, underscores only.</p>
            </div>
        </div>

        {{-- Description --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Description</label>
            <textarea name="description" rows="2"
                      placeholder="Short description shown on the plan card"
                      class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none">{{ old('description', $plan->description) }}</textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            {{-- Sort Order --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Sort Order <span class="text-red-500">*</span></label>
                <input type="number" name="sort_order" value="{{ old('sort_order', $plan->sort_order ?? 0) }}"
                       min="0"
                       class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
            </div>

            {{-- Status toggles --}}
            <div class="flex flex-col gap-3 pt-1">
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           {{ old('is_active', $plan->is_active ?? true) ? 'checked' : '' }}
                           class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                    <span class="text-sm font-medium text-gray-700">Active</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input type="hidden" name="is_featured" value="0">
                    <input type="checkbox" name="is_featured" value="1"
                           {{ old('is_featured', $plan->is_featured ?? false) ? 'checked' : '' }}
                           class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                    <span class="text-sm font-medium text-gray-700">Featured <span class="text-xs text-indigo-500">(Popular badge)</span></span>
                </label>
            </div>
        </div>
    </div>

    {{-- ── Pricing ─────────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Pricing</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            {{-- Monthly Price --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Monthly Price (₹) <span class="text-red-500">*</span></label>
                <input type="number" name="monthly_price" value="{{ old('monthly_price', $plan->monthly_price ?? 0) }}"
                       min="0" step="0.01" placeholder="0"
                       class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <p class="text-xs text-gray-400 mt-1">Set 0 for a free plan.</p>
            </div>

            {{-- Yearly Price --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Yearly Price (₹) <span class="text-red-500">*</span></label>
                <input type="number" name="yearly_price" value="{{ old('yearly_price', $plan->yearly_price ?? 0) }}"
                       min="0" step="0.01" placeholder="0"
                       class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                <p class="text-xs text-gray-400 mt-1">Set 0 to hide yearly option.</p>
            </div>

            {{-- Currency --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Currency <span class="text-red-500">*</span></label>
                <select name="currency"
                        class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent bg-white">
                    @foreach (['INR', 'USD', 'EUR', 'GBP'] as $cur)
                        <option value="{{ $cur }}" {{ old('currency', $plan->currency ?? 'INR') === $cur ? 'selected' : '' }}>
                            {{ $cur }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ── Limits ──────────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5">
        <div>
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Limits</h2>
            <p class="text-xs text-gray-400 mt-1">Enter <code class="bg-gray-100 px-1 rounded">-1</code> for unlimited.</p>
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 md:grid-cols-3">
            @php
                $limitFields = [
                    'leads_limit'        => ['label' => 'Leads',        'default' => 500],
                    'deals_limit'        => ['label' => 'Deals',        'default' => 200],
                    'pipelines_limit'    => ['label' => 'Pipelines',    'default' => 1],
                    'team_members_limit' => ['label' => 'Team Members', 'default' => 1],
                    'departments_limit'  => ['label' => 'Departments',  'default' => 1],
                ];
            @endphp

            @foreach ($limitFields as $field => $meta)
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ $meta['label'] }} <span class="text-red-500">*</span></label>
                    <input type="number" name="{{ $field }}"
                           value="{{ old($field, $plan->{$field} ?? $meta['default']) }}"
                           min="-1"
                           class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Feature Flags ───────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Feature Flags</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
            @php
                $featureFlags = [
                    'bulk_import'      => 'Bulk Import',
                    'sso_access'       => 'SSO Access',
                    'api_access'       => 'API Access',
                    'advanced_reports' => 'Advanced Reports',
                    'priority_support' => 'Priority Support',
                    'custom_pipelines' => 'Custom Pipelines',
                ];
            @endphp

            @foreach ($featureFlags as $field => $label)
                <label class="flex items-center gap-3 cursor-pointer select-none p-3 rounded-xl border border-gray-100 hover:bg-gray-50 transition-colors">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input type="checkbox" name="{{ $field }}" value="1"
                           {{ old($field, $plan->{$field} ?? false) ? 'checked' : '' }}
                           class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                    <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- ── Feature List ─────────────────────────────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5">
        <div>
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">What's Included</h2>
            <p class="text-xs text-gray-400 mt-1">One feature per line. These are shown as bullet points on the plan card.</p>
        </div>

        <textarea name="feature_list_raw" rows="8"
                  placeholder="e.g.&#10;Up to 500 leads&#10;Email support&#10;Analytics dashboard"
                  class="w-full border border-gray-300 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-y font-mono">{{ old('feature_list_raw', is_array($plan->feature_list) ? implode("\n", $plan->feature_list) : '') }}</textarea>
    </div>

    {{-- ── Submit ───────────────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-end gap-3 pb-6">
        <a href="{{ route('plans.index') }}"
           class="px-5 py-2.5 text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors">
            Cancel
        </a>
        <button type="submit"
                class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors">
            {{ $plan->exists ? 'Save Changes' : 'Create Plan' }}
        </button>
    </div>

</form>

@endsection
