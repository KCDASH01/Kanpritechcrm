@extends('layouts.admin')

@section('title', 'Activate Subscription — ' . $organization->name)
@section('page-title', 'Activate Subscription')

@section('content')
<div class="max-w-2xl">
    <div class="mb-5">
        <a href="{{ route('organizations.show', $organization) }}" class="text-sm text-indigo-600 hover:text-indigo-700">← Back to {{ $organization->name }}</a>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-8">
        {{-- Current plan notice --}}
        @php $current = $organization->activeSubscription; @endphp
        @if ($current)
            <div class="mb-6 px-4 py-3 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 text-sm">
                Current active plan: <strong>{{ ucfirst($current->plan) }}</strong> ({{ $current->subscription_source }})
                — activating a new one will cancel this.
            </div>
        @endif

        <form method="POST" action="{{ route('subscriptions.activate', $organization) }}" class="space-y-5">
            @csrf

            @if ($errors->any())
                <div class="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            {{-- Plan + Source row --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Plan *</label>
                    <select name="plan" required
                            class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="free" {{ old('plan') === 'free' ? 'selected' : '' }}>Free</option>
                        <option value="business" {{ old('plan', 'business') === 'business' ? 'selected' : '' }}>Business</option>
                        <option value="enterprise" {{ old('plan') === 'enterprise' ? 'selected' : '' }}>Enterprise</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Source *</label>
                    <select name="source" required
                            class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="internal" {{ old('source', 'internal') === 'internal' ? 'selected' : '' }}>Internal</option>
                        <option value="external" {{ old('source') === 'external' ? 'selected' : '' }}>External (SSO sync)</option>
                    </select>
                </div>
            </div>

            {{-- Dates --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Start Date *</label>
                    <input type="date" name="start_date" required value="{{ old('start_date', now()->toDateString()) }}"
                           class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">End Date <span class="text-gray-400">(leave blank = no expiry)</span></label>
                    <input type="date" name="end_date" value="{{ old('end_date') }}"
                           class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            {{-- Amount --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Amount</label>
                    <input type="number" name="amount" step="0.01" min="0" value="{{ old('amount') }}"
                           placeholder="0.00"
                           class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Currency</label>
                    <input type="text" name="currency" maxlength="3" value="{{ old('currency', 'INR') }}"
                           class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Notes</label>
                <textarea name="notes" rows="3" placeholder="Internal notes…"
                          class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none">{{ old('notes') }}</textarea>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition-colors">
                    Activate Subscription
                </button>
                <a href="{{ route('organizations.show', $organization) }}"
                   class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
