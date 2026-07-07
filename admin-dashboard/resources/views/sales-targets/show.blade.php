@extends('layouts.admin')
@section('title', $user->name . ' — Sales Targets')

@section('content')
<div class="p-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('sales-targets.index') }}" class="text-gray-400 hover:text-gray-600 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div class="flex-1">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-sm">
                    {{ strtoupper(substr($user->name, 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900">{{ $user->name }}</h1>
                    <p class="text-sm text-gray-500">{{ $org->name }} · Sales Target Performance</p>
                </div>
            </div>
        </div>
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

    {{-- ─── Current month progress cards (mirrors team member dashboard) ───── --}}
    @php
        $current  = end($history);
        reset($history);

        function fmtA($n) {
            if ($n === null) return null;
            if ($n >= 10000000) return '₹' . number_format($n/10000000, 2) . 'Cr';
            if ($n >= 100000)   return '₹' . number_format($n/100000, 2) . 'L';
            if ($n >= 1000)     return '₹' . number_format($n/1000, 1) . 'K';
            return '₹' . number_format($n, 0);
        }

        $salesPctCur = $current['target_amount'] > 0
            ? min(round(($current['achieved_amount'] / $current['target_amount']) * 100), 100) : 0;
        $salesRawPct = $current['target_amount'] > 0
            ? round(($current['achieved_amount'] / $current['target_amount']) * 100, 1) : 0;
        $collPctCur  = ($current['receivable_amount'] > 0 && $current['received_amount'] !== null)
            ? min(round(($current['received_amount'] / $current['receivable_amount']) * 100), 100) : 0;
        $collRawPct  = ($current['receivable_amount'] > 0 && $current['received_amount'] !== null)
            ? round(($current['received_amount'] / $current['receivable_amount']) * 100, 1) : 0;
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

        {{-- Sales Target Card --}}
        <div class="relative bg-gradient-to-br from-indigo-500 to-violet-600 rounded-2xl p-6 text-white shadow-lg overflow-hidden">
            <div class="absolute -right-6 -top-6 w-28 h-28 bg-white/10 rounded-full"></div>
            <div class="absolute -right-2 top-2 w-16 h-16 bg-white/10 rounded-full flex items-center justify-center">
                <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <p class="text-white/70 text-[11px] font-semibold uppercase tracking-wider mb-2">Sales Target — {{ $current['month_label'] }}</p>
            <p class="text-3xl font-bold leading-none">{{ fmtA($current['achieved_amount']) ?? '₹0' }}</p>
            <p class="text-white/60 text-xs mt-1.5">of {{ fmtA($current['target_amount']) ?? '₹0' }} target</p>
            <div class="mt-4">
                <div class="flex justify-between text-white/70 text-[10px] mb-1">
                    <span>Won deals this month</span>
                    <span class="font-bold">{{ $salesRawPct }}%</span>
                </div>
                <div class="h-2 bg-white/20 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all {{ $salesRawPct >= 100 ? 'bg-white' : ($salesRawPct >= 50 ? 'bg-white/80' : 'bg-white/50') }}"
                         style="width: {{ $salesPctCur }}%"></div>
                </div>
            </div>
        </div>

        {{-- Collections Card --}}
        <div class="relative bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-6 text-white shadow-lg overflow-hidden">
            <div class="absolute -right-6 -top-6 w-28 h-28 bg-white/10 rounded-full"></div>
            <div class="absolute -right-2 top-2 w-16 h-16 bg-white/10 rounded-full flex items-center justify-center">
                <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                </svg>
            </div>
            <p class="text-white/70 text-[11px] font-semibold uppercase tracking-wider mb-2">Collections — {{ $current['month_label'] }}</p>
            <p class="text-3xl font-bold leading-none">
                {{ $current['received_amount'] !== null ? fmtA($current['received_amount']) : '—' }}
            </p>
            <p class="text-white/60 text-xs mt-1.5">of {{ fmtA($current['receivable_amount']) ?? '₹0' }} target</p>
            @if ($current['receivable_amount'] > 0 && $current['received_amount'] !== null)
                <div class="mt-4">
                    <div class="flex justify-between text-white/70 text-[10px] mb-1">
                        <span>Amount collected</span>
                        <span class="font-bold">{{ $collRawPct }}%</span>
                    </div>
                    <div class="h-2 bg-white/20 rounded-full overflow-hidden">
                        <div class="h-full rounded-full {{ $collRawPct >= 100 ? 'bg-white' : ($collRawPct >= 50 ? 'bg-white/80' : 'bg-white/50') }}"
                             style="width: {{ $collPctCur }}%"></div>
                    </div>
                </div>
            @else
                <p class="mt-3 text-white/50 text-xs">Received amount not entered yet</p>
            @endif
        </div>
    </div>

    {{-- ─── Chart.js ─────────────────────────────────────────────────────────── --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-1">Sales Performance — 6 Months</h2>
            <p class="text-[11px] text-gray-400 mb-5">Target vs Won deals value per month</p>
            <canvas id="salesChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-1">Collections Performance — 6 Months</h2>
            <p class="text-[11px] text-gray-400 mb-5">Collection target vs amount received per month</p>
            <canvas id="collChart" height="220"></canvas>
        </div>
    </div>

    {{-- ─── History table with inline edit ───────────────────────────────────── --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Monthly History & Target Editor</h2>
                <p class="text-[11px] text-gray-400 mt-0.5">Click Edit to update targets for any month</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="text-left px-6 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Month</th>
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
                    @foreach (array_reverse($history) as $h)
                        @php
                            $sp = $h['sales_pct'];
                            $cp = $h['coll_pct'];
                            function pctBadge2($pct) {
                                if ($pct === null) return '<span class="text-gray-300 text-xs">—</span>';
                                $cls = $pct >= 100 ? 'text-emerald-600 bg-emerald-50' : ($pct >= 50 ? 'text-amber-600 bg-amber-50' : 'text-red-600 bg-red-50');
                                return "<span class=\"text-[11px] font-bold px-2 py-0.5 rounded-full {$cls}\">{$pct}%</span>";
                            }
                        @endphp
                        {{-- Display row --}}
                        <tr id="row-{{ $h['period_start'] }}" class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-6 py-3.5 font-medium text-gray-800 text-sm">{{ $h['month_label'] }}</td>
                            <td class="px-4 py-3.5 text-right text-gray-500 text-xs">{{ $h['target_amount'] > 0 ? fmtA($h['target_amount']) : '—' }}</td>
                            <td class="px-4 py-3.5 text-right text-gray-700 text-xs font-semibold">{{ fmtA($h['achieved_amount']) }}</td>
                            <td class="px-4 py-3.5 text-center">{!! pctBadge2($h['target_amount'] > 0 ? $sp : null) !!}</td>
                            <td class="px-4 py-3.5 text-right text-gray-500 text-xs border-l border-gray-100">{{ $h['receivable_amount'] > 0 ? fmtA($h['receivable_amount']) : '—' }}</td>
                            <td class="px-4 py-3.5 text-right text-gray-700 text-xs font-semibold">{{ $h['received_amount'] !== null ? fmtA($h['received_amount']) : '—' }}</td>
                            <td class="px-4 py-3.5 text-center">{!! pctBadge2($cp) !!}</td>
                            <td class="px-4 py-3.5 text-center">
                                @if ($h['target_id'])
                                    <button onclick="showEditRow('{{ $h['period_start'] }}')"
                                        class="text-indigo-600 hover:text-indigo-700 text-xs font-medium">Edit</button>
                                @else
                                    <button onclick="openSetTargetForm('{{ $h['period_start'] }}')"
                                        class="text-emerald-600 hover:text-emerald-700 text-xs font-medium">Set Target</button>
                                @endif
                            </td>
                        </tr>

                        {{-- Inline edit row (hidden by default) --}}
                        @if ($h['target_id'])
                            <tr id="edit-{{ $h['period_start'] }}" class="hidden bg-indigo-50/40">
                                <td class="px-6 py-3 font-medium text-gray-800 text-sm">{{ $h['month_label'] }}</td>
                                <form method="POST" action="{{ route('sales-targets.update', $h['target_id']) }}" class="contents">
                                    @csrf @method('PUT')
                                    <td class="px-4 py-3" colspan="3">
                                        <div class="flex items-center gap-2">
                                            <label class="text-xs text-gray-500 shrink-0">Sales Target ₹</label>
                                            <input type="number" name="target_amount" value="{{ $h['target_amount'] }}" min="0" step="1000"
                                                class="w-36 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400" />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 border-l border-gray-100" colspan="3">
                                        <div class="flex items-center gap-2">
                                            <label class="text-xs text-gray-500 shrink-0">Collection Target ₹</label>
                                            <input type="number" name="receivable_amount" value="{{ $h['receivable_amount'] }}" min="0" step="1000"
                                                class="w-36 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400" />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="submit" class="bg-indigo-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-indigo-700 transition-colors">Save</button>
                                            <button type="button" onclick="hideEditRow('{{ $h['period_start'] }}')" class="text-gray-400 hover:text-gray-600 text-xs px-2 py-1.5">Cancel</button>
                                        </div>
                                    </td>
                                </form>
                            </tr>
                        @endif

                        {{-- New target form row --}}
                        @if (!$h['target_id'])
                            <tr id="new-{{ $h['period_start'] }}" class="hidden bg-emerald-50/40">
                                <td class="px-6 py-3 font-medium text-gray-800 text-sm">{{ $h['month_label'] }}</td>
                                <form method="POST" action="{{ route('sales-targets.store') }}" class="contents">
                                    @csrf
                                    <input type="hidden" name="organization_id" value="{{ $org->id }}">
                                    <input type="hidden" name="user_id"         value="{{ $user->id }}">
                                    <input type="hidden" name="period_start"    value="{{ $h['period_start'] }}">
                                    <td class="px-4 py-3" colspan="3">
                                        <div class="flex items-center gap-2">
                                            <label class="text-xs text-gray-500 shrink-0">Sales Target ₹</label>
                                            <input type="number" name="target_amount" min="0" step="1000" placeholder="e.g. 500000"
                                                class="w-36 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-400" />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 border-l border-gray-100" colspan="3">
                                        <div class="flex items-center gap-2">
                                            <label class="text-xs text-gray-500 shrink-0">Collection Target ₹</label>
                                            <input type="number" name="receivable_amount" min="0" step="1000" placeholder="e.g. 200000"
                                                class="w-36 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-400" />
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="submit" class="bg-emerald-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-emerald-700 transition-colors">Save</button>
                                            <button type="button" onclick="hideSetRow('{{ $h['period_start'] }}')" class="text-gray-400 hover:text-gray-600 text-xs px-2 py-1.5">Cancel</button>
                                        </div>
                                    </td>
                                </form>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const labels        = {!! json_encode($labels) !!};
    const salesTargets  = {!! json_encode($salesTargets) !!};
    const salesAchieved = {!! json_encode($salesAchieved) !!};
    const collTargets   = {!! json_encode($collTargets) !!};
    const collReceived  = {!! json_encode($collReceived) !!};

    const baseOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 }, color: '#64748b', boxWidth: 12, padding: 16 } },
            tooltip: { callbacks: { label: (ctx) => ' ₹' + Number(ctx.raw).toLocaleString('en-IN') } },
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94a3b8' } },
            y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#94a3b8', callback: v => '₹' + (v >= 100000 ? (v/100000).toFixed(1) + 'L' : v >= 1000 ? (v/1000).toFixed(0) + 'K' : v) } },
        },
    };

    new Chart(document.getElementById('salesChart'), {
        type: 'bar', options: baseOptions,
        data: { labels, datasets: [
            { label: 'Target',   data: salesTargets,  backgroundColor: '#c7d2fe', borderRadius: 4 },
            { label: 'Achieved', data: salesAchieved, backgroundColor: '#6366f1', borderRadius: 4 },
        ]},
    });

    new Chart(document.getElementById('collChart'), {
        type: 'bar', options: baseOptions,
        data: { labels, datasets: [
            { label: 'Collection Target', data: collTargets,  backgroundColor: '#a7f3d0', borderRadius: 4 },
            { label: 'Received',          data: collReceived, backgroundColor: '#10b981', borderRadius: 4 },
        ]},
    });
})();

function showEditRow(period) {
    document.getElementById('row-'  + period).classList.add('hidden');
    document.getElementById('edit-' + period).classList.remove('hidden');
}
function hideEditRow(period) {
    document.getElementById('edit-' + period).classList.add('hidden');
    document.getElementById('row-'  + period).classList.remove('hidden');
}
function openSetTargetForm(period) {
    document.getElementById('row-'  + period).classList.add('hidden');
    document.getElementById('new-'  + period).classList.remove('hidden');
}
function hideSetRow(period) {
    document.getElementById('new-'  + period).classList.add('hidden');
    document.getElementById('row-'  + period).classList.remove('hidden');
}
</script>
@endsection
