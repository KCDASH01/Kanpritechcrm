@extends('layouts.admin')

@section('title', 'Organizations — CRM Admin')
@section('page-title', 'Organizations')

@section('content')
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
        <p class="text-sm text-gray-500">{{ $organizations->total() }} organizations total</p>
    </div>

    <div class="touch-scroll overflow-x-auto">
        <table class="min-w-[760px] w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Organization</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Owner</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Plan</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Users</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Created</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($organizations as $org)
                    @php $sub = $org->activeSubscription; @endphp
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center shrink-0">
                                    <span class="text-indigo-600 font-semibold text-xs">{{ strtoupper(substr($org->name, 0, 1)) }}</span>
                                </div>
                                <div>
                                    <a href="{{ route('organizations.show', $org) }}"
                                       class="font-medium text-gray-900 hover:text-indigo-600 text-sm">{{ $org->name }}</a>
                                    <p class="text-xs text-gray-400">{{ $org->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $org->owner?->name ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ ($sub?->plan === 'business') ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ $sub ? ucfirst($sub->plan) : 'No sub' }}
                                @if($sub?->subscription_source === 'external')
                                    <span class="ml-1 opacity-60">(ext)</span>
                                @endif
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $org->users_count }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $org->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $org->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ $org->created_at->format('M j, Y') }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('organizations.show', $org) }}"
                               class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">View →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-10 text-center text-gray-400 text-sm">No organizations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($organizations->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">
            {{ $organizations->links() }}
        </div>
    @endif
</div>
@endsection
