@extends('layouts.admin')

@section('title', 'Subscriptions — CRM Admin')
@section('page-title', 'Subscriptions')

@section('content')
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
        <p class="text-sm text-gray-500">{{ $subscriptions->total() }} subscriptions total</p>
    </div>
    <div class="touch-scroll overflow-x-auto">
        <table class="min-w-[760px] w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Organization</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Plan</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Source</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Period</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($subscriptions as $sub)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-6 py-4 text-sm">
                            @if ($sub->organization)
                                <a href="{{ route('organizations.show', $sub->organization) }}"
                                   class="font-medium text-gray-900 hover:text-indigo-600">{{ $sub->organization->name }}</a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $sub->plan === 'business' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($sub->plan) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600 capitalize">{{ $sub->subscription_source }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            {{ $sub->start_date?->format('M j, Y') }} — {{ $sub->end_date ? $sub->end_date->format('M j, Y') : '∞' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            @if ($sub->amount)
                                {{ $sub->currency }} {{ number_format($sub->amount, 2) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $sub->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $sub->status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            @if ($sub->is_active)
                                <form method="POST" action="{{ route('subscriptions.cancel', $sub) }}" class="inline">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Cancel this subscription?')"
                                            class="text-xs text-red-600 hover:text-red-700 font-medium">Cancel</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-10 text-center text-gray-400 text-sm">No subscriptions.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($subscriptions->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $subscriptions->links() }}</div>
    @endif
</div>
@endsection
