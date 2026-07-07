@extends('layouts.admin')
@section('title', 'Sales Targets — CRM Admin')

@section('content')
<div class="p-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Sales Targets</h1>
            <p class="text-sm text-gray-500 mt-0.5">Set and track monthly sales & collection targets for all team members</p>
        </div>
        <button onclick="document.getElementById('setTargetModal').classList.remove('hidden')"
            class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Set Target
        </button>
    </div>

    {{-- Success message --}}
    @if ($success)
        <div class="flex items-center gap-3 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm px-4 py-3 rounded-xl">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            {{ $success }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-4">
        <form method="GET" action="{{ route('sales-targets.index') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wide">Month</label>
                <input type="month" name="month" value="{{ $monthParam }}"
                    class="border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wide">Organization</label>
                <select name="org" class="border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">All Organizations</option>
                    @foreach ($orgs as $org)
                        <option value="{{ $org->id }}" {{ $orgId == $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors">
                Filter
            </button>
            <a href="{{ route('sales-targets.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-2 underline">
                Clear
            </a>
        </form>
    </div>

    {{-- Results --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-900">
                @php
                    [$y, $m] = explode('-', $monthParam);
                    echo (new DateTime("{$y}-{$m}-01"))->format('F Y');
                @endphp
                — {{ $rows->count() }} record{{ $rows->count() !== 1 ? 's' : '' }}
            </p>
        </div>

        @if ($rows->isEmpty())
            <div class="px-6 py-16 text-center">
                <div class="text-4xl mb-3">🎯</div>
                <p class="text-gray-600 font-semibold">No sales targets found for this month</p>
                <p class="text-sm text-gray-400 mt-1">Click <strong>"Set Target"</strong> to assign targets to team members.</p>
            </div>
        @else
            @php
                function pctBadge($pct) {
                    if ($pct === null) return '<span class="text-gray-300 text-xs">—</span>';
                    $cls = $pct >= 100 ? 'text-emerald-600 bg-emerald-50' : ($pct >= 50 ? 'text-amber-600 bg-amber-50' : 'text-red-600 bg-red-50');
                    return "<span class=\"text-[11px] font-bold px-2 py-0.5 rounded-full {$cls}\">{$pct}%</span>";
                }
                function fmtAmt($n) {
                    if ($n >= 10000000) return '₹' . number_format($n/10000000, 2) . 'Cr';
                    if ($n >= 100000)   return '₹' . number_format($n/100000, 2) . 'L';
                    if ($n >= 1000)     return '₹' . number_format($n/1000, 1) . 'K';
                    return '₹' . number_format($n, 0);
                }
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="text-left px-6 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Rep</th>
                            <th class="text-left px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Organization</th>
                            <th class="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Sales Target</th>
                            <th class="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Achieved</th>
                            <th class="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">%</th>
                            <th class="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide border-l border-gray-100">Collection Target</th>
                            <th class="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Received</th>
                            <th class="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">%</th>
                            <th class="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 text-xs font-bold shrink-0">
                                            {{ strtoupper(substr($row['user']->name ?? '?', 0, 1)) }}
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-800 text-sm">{{ $row['user']->name ?? '—' }}</p>
                                            <p class="text-[10px] text-gray-400">{{ $row['user']->email ?? '' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-gray-600 text-xs">{{ $row['org']->name ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-right text-gray-500 text-xs">{{ fmtAmt($row['target']->target_amount) }}</td>
                                <td class="px-4 py-3.5 text-right text-gray-700 text-xs font-semibold">{{ fmtAmt($row['achieved']) }}</td>
                                <td class="px-4 py-3.5 text-center">{!! pctBadge($row['sales_pct']) !!}</td>
                                <td class="px-4 py-3.5 text-right text-gray-500 text-xs border-l border-gray-100">{{ fmtAmt($row['target']->receivable_amount) }}</td>
                                <td class="px-4 py-3.5 text-right text-xs font-semibold text-gray-700">
                                    {{ $row['target']->received_amount !== null ? fmtAmt($row['target']->received_amount) : '—' }}
                                </td>
                                <td class="px-4 py-3.5 text-center">{!! pctBadge($row['coll_pct']) !!}</td>
                                <td class="px-4 py-3.5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        @if ($row['org'] && $row['user'])
                                            {{-- Quick edit: open Set Target modal pre-filled --}}
                                            <button
                                                onclick="openEditModal({{ $row['target']->id }}, {{ $row['org']->id }}, {{ $row['user']->id }}, '{{ $row['user']->name }}', '{{ $row['target']->period_start->format('Y-m') }}', {{ $row['target']->target_amount }}, {{ $row['target']->receivable_amount }})"
                                                class="text-indigo-600 hover:text-indigo-700 text-xs font-medium">
                                                Edit
                                            </button>
                                            <span class="text-gray-200">|</span>
                                            <a href="{{ route('sales-targets.show', [$row['org']->id, $row['user']->id]) }}"
                                               class="text-gray-500 hover:text-gray-700 text-xs font-medium">
                                                Details →
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>

{{-- ════════════════════════════════════════════════════════════════
     Set Target Modal
════════════════════════════════════════════════════════════════ --}}
<div id="setTargetModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">

        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900" id="modalTitle">Set Sales Target</h2>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form id="setTargetForm" method="POST" action="{{ route('sales-targets.store') }}" class="p-6 space-y-4">
            @csrf
            {{-- Hidden method field for PUT (edit mode) --}}
            <input type="hidden" name="_method" id="formMethod" value="POST">
            <input type="hidden" name="target_id"        id="targetId"        value="">

            {{-- Organization --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Organization *</label>
                <select name="organization_id" id="modalOrg"
                    onchange="populateUsers(this.value)"
                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">Select organization…</option>
                    @foreach ($orgs as $org)
                        <option value="{{ $org->id }}">{{ $org->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- User --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Team Member *</label>
                <select name="user_id" id="modalUser"
                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">Select team member…</option>
                </select>
            </div>

            {{-- Month --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Month *</label>
                <input type="month" name="period_start" id="modalMonth"
                    value="{{ $monthParam }}"
                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            </div>

            {{-- Amounts --}}
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Sales Target (₹) *</label>
                    <input type="number" name="target_amount" id="modalTargetAmount"
                        min="0" step="1000" placeholder="e.g. 500000"
                        class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Collection Target (₹) *</label>
                    <input type="number" name="receivable_amount" id="modalReceivable"
                        min="0" step="1000" placeholder="e.g. 200000"
                        class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal()"
                    class="flex-1 border border-gray-200 text-gray-600 py-2.5 rounded-xl text-sm hover:bg-gray-50 transition-colors font-medium">
                    Cancel
                </button>
                <button type="submit"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-sm font-semibold transition-colors">
                    Save Target
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Users by org JSON for JS --}}
<script>
const usersByOrg = @json($usersByOrg);

function populateUsers(orgId) {
    const select = document.getElementById('modalUser');
    select.innerHTML = '<option value="">Select team member…</option>';
    if (!orgId || !usersByOrg[orgId]) return;
    usersByOrg[orgId].forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.name + ' (' + u.email + ')';
        select.appendChild(opt);
    });
}

function closeModal() {
    document.getElementById('setTargetModal').classList.add('hidden');
    // Reset form
    document.getElementById('modalTitle').textContent = 'Set Sales Target';
    document.getElementById('formMethod').value = 'POST';
    document.getElementById('setTargetForm').action = '{{ route('sales-targets.store') }}';
    document.getElementById('modalOrg').value = '';
    document.getElementById('modalUser').innerHTML = '<option value="">Select team member…</option>';
    document.getElementById('modalMonth').value = '{{ $monthParam }}';
    document.getElementById('modalTargetAmount').value = '';
    document.getElementById('modalReceivable').value = '';
}

function openEditModal(targetId, orgId, userId, userName, month, targetAmount, receivable) {
    document.getElementById('modalTitle').textContent = 'Edit Target — ' + userName;
    document.getElementById('formMethod').value = 'PUT';
    document.getElementById('setTargetForm').action = '/sales-targets/' + targetId;
    document.getElementById('modalOrg').value = orgId;
    populateUsers(orgId);
    // Set user after population (setTimeout to let DOM update)
    setTimeout(() => { document.getElementById('modalUser').value = userId; }, 50);
    document.getElementById('modalMonth').value = month;
    document.getElementById('modalTargetAmount').value = targetAmount;
    document.getElementById('modalReceivable').value = receivable;
    document.getElementById('setTargetModal').classList.remove('hidden');
}
</script>
@endsection
