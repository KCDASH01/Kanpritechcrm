<?php

namespace App\Http\Controllers\SalesTarget;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\TargetProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesTargetController extends Controller
{
    public function __construct(private readonly TargetProgressService $progress) {}

    private function orgId(Request $request): int
    {
        return (int) $request->user()->organization_id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['owner', 'admin'], true);
    }

    private function organizationNow(Request $request): Carbon
    {
        $timezone = $request->user()->organization?->timezone ?: config('app.timezone');
        return Carbon::now($timezone);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'user_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'target_type' => ['nullable', Rule::in(['monthly', 'custom'])],
            'status' => ['nullable', Rule::in(['active', 'upcoming', 'completed'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $orgId = $this->orgId($request);
        $user = $request->user();
        $canManage = $this->canManage($request);
        $now = $this->organizationNow($request)->startOfDay();

        $query = SalesTarget::query()
            ->where('organization_id', $orgId)
            ->whereHas('user', fn (Builder $member) => $member->where('is_active', true)->where('role', '!=', 'owner'))
            ->with(['user:id,name,email', 'user.departments:id,name']);

        if (!$canManage) {
            $query->where('user_id', $user->id);
        } elseif (!empty($data['user_id'])) {
            $query->where('user_id', $data['user_id']);
        }

        if (!empty($data['department_id'])) {
            $query->whereHas('user.departments', fn (Builder $department) => $department->where('departments.id', $data['department_id']));
        }
        if (!empty($data['target_type'])) $query->where('target_type', $data['target_type']);

        if (!empty($data['month'])) {
            [$windowStart, $windowEnd] = $this->progress->period($data['month']);
            $this->overlapWindow($query, $windowStart, $windowEnd);
        }
        if (!empty($data['from']) || !empty($data['to'])) {
            $from = $data['from'] ?? '1900-01-01';
            $to = $data['to'] ?? '2999-12-31';
            $this->overlapWindow($query, $from, $to);
        }

        if (!empty($data['status'])) {
            match ($data['status']) {
                'active' => $query->whereDate('period_start', '<=', $now->toDateString())
                    ->whereDate('period_end', '>=', $now->toDateString()),
                'upcoming' => $query->whereDate('period_start', '>', $now->toDateString()),
                'completed' => $query->whereDate('period_end', '<', $now->toDateString()),
            };
        }

        $rows = $query->orderByDesc('period_start')->orderBy('user_id')->get()
            ->map(fn (SalesTarget $target) => $this->targetPayload($target));

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            return response()->json(['message' => 'Only managers can set targets.'], 403);
        }

        $orgId = $this->orgId($request);
        $data = $request->validate([
            'target_id' => ['nullable', 'integer'],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->where('organization_id', $orgId)->where('is_active', true)->where('role', '!=', 'owner'))],
            'target_type' => ['required', Rule::in(['monthly', 'custom'])],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'receivable_amount' => ['required', 'numeric', 'min:0'],
            'period_start' => ['required', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $start = Carbon::parse($data['period_start'])->startOfDay();
        if ($data['target_type'] === 'custom' && empty($data['period_end'])) {
            throw ValidationException::withMessages(['period_end' => 'End date is required for a custom target period.']);
        }
        $end = $data['target_type'] === 'monthly'
            ? $start->copy()->startOfMonth()->endOfMonth()
            : Carbon::parse($data['period_end'])->startOfDay();
        if ($data['target_type'] === 'monthly') $start->startOfMonth();

        $target = !empty($data['target_id'])
            ? SalesTarget::where('organization_id', $orgId)->findOrFail($data['target_id'])
            : SalesTarget::query()->where('organization_id', $orgId)->where('user_id', $data['user_id'])
                ->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->first();

        $overlap = SalesTarget::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $data['user_id'])
            ->when($target, fn (Builder $query) => $query->whereKeyNot($target->id))
            ->whereDate('period_start', '<=', $end->toDateString())
            ->where(function (Builder $query) use ($start) {
                $query->whereDate('period_end', '>=', $start->toDateString())
                    ->orWhere(function (Builder $legacy) use ($start) {
                        $legacy->whereNull('period_end')->whereDate('period_start', '>=', $start->copy()->startOfMonth()->toDateString());
                    });
            })->first();

        if ($overlap) {
            throw ValidationException::withMessages([
                'period_start' => 'This period overlaps an existing target from '.Carbon::parse($overlap->period_start)->format('d M Y').' to '.$this->progress->periodEnd($overlap)->format('d M Y').'.',
            ]);
        }

        $target = DB::transaction(function () use ($request, $data, $orgId, $target, $start, $end): SalesTarget {
            $before = $target?->only(['user_id', 'target_type', 'period_start', 'period_end', 'target_amount', 'receivable_amount', 'notes']);
            $isNew = !$target;
            $target ??= new SalesTarget([
                'organization_id' => $orgId,
                'created_by' => $request->user()->id,
            ]);
            $target->fill([
                'user_id' => $data['user_id'],
                'target_type' => $data['target_type'],
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'target_amount' => $data['target_amount'],
                'receivable_amount' => $data['receivable_amount'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $request->user()->id,
            ])->save();

            DB::table('sales_target_audits')->insert([
                'sales_target_id' => $target->id,
                'organization_id' => $orgId,
                'user_id' => $target->user_id,
                'actor_id' => $request->user()->id,
                'action' => $isNew ? 'created' : 'updated',
                'before_values' => $before ? json_encode($before) : null,
                'after_values' => json_encode($target->only(['user_id', 'target_type', 'period_start', 'period_end', 'target_amount', 'receivable_amount', 'notes'])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $target;
        });

        return response()->json(['data' => $this->targetPayload($target->load(['user', 'user.departments']))]);
    }

    public function details(Request $request, SalesTarget $salesTarget): JsonResponse
    {
        if ((int) $salesTarget->organization_id !== $this->orgId($request)) abort(404);
        if (!$this->canManage($request) && (int) $salesTarget->user_id !== (int) $request->user()->id) abort(403);

        $start = Carbon::parse($salesTarget->period_start)->toDateString();
        $end = $this->progress->periodEnd($salesTarget)->toDateString();
        $salesTarget->load(['user:id,name,email', 'user.departments:id,name']);
        $payload = $this->targetPayload($salesTarget);
        $payload['deals'] = $this->progress->salesDetailsQuery($this->orgId($request), (int) $salesTarget->user_id, $start, $end)
            ->with(['client:id,company,first_name,last_name'])->orderByDesc('closed_at')->limit(200)->get()
            ->map(fn ($deal) => [
                'id' => $deal->id,
                'title' => $deal->title,
                'client' => $deal->client?->company ?: $deal->client?->full_name,
                'amount' => (float) $deal->value,
                'date' => $deal->closed_at?->toDateString(),
            ])->values();
        $payload['payments'] = $this->progress->collectionDetailsQuery($this->orgId($request), (int) $salesTarget->user_id, $start, $end)
            ->with('deal:id,title')->orderByDesc('payment_date')->limit(200)->get()
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'deal_id' => $payment->deal_id,
                'deal' => $payment->deal?->title,
                'amount' => (float) $payment->amount,
                'date' => $payment->payment_date?->toDateString(),
                'method' => $payment->payment_mode,
            ])->values();

        return response()->json(['data' => $payload]);
    }

    public function updateReceived(Request $request, SalesTarget $salesTarget): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden.'], 403);
        if ((int) $salesTarget->organization_id !== $this->orgId($request)) abort(404);

        $data = $request->validate([
            'received_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $salesTarget->update($data + ['updated_by' => $request->user()->id]);

        return response()->json(['data' => $salesTarget->fresh()]);
    }

    public function myProgress(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->history($this->orgId($request), (int) $request->user()->id)]);
    }

    public function userProgress(Request $request, User $user): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden.'], 403);
        if ((int) $user->organization_id !== $this->orgId($request)) return response()->json(['message' => 'Forbidden.'], 403);

        return response()->json([
            'data' => $this->history((int) $user->organization_id, (int) $user->id),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', Rule::in(['sales', 'collection', 'overall'])],
            'period_scope' => ['nullable', Rule::in(['active', 'completed'])],
            'department_id' => ['nullable', 'integer'],
            'mode' => ['nullable', Rule::in(['actual', 'pace'])],
        ]);
        $settings = $this->leaderboardSettings($request->user()->organization);
        $canManage = $this->canManage($request);
        if ($settings['visibility'] === 'disabled' || (!$canManage && $settings['visibility'] !== 'everyone')) {
            return response()->json(['message' => 'Leaderboard is not available.'], 403);
        }

        $now = $this->organizationNow($request)->startOfDay();
        $scope = $data['period_scope'] ?? 'active';
        $query = SalesTarget::query()->where('organization_id', $this->orgId($request))
            ->whereHas('user', function (Builder $user) use ($data) {
                $user->where('is_active', true)->where('role', '!=', 'owner')
                    ->when($data['department_id'] ?? null, fn (Builder $member) => $member->whereHas('departments', fn (Builder $department) => $department->where('departments.id', $data['department_id'])));
            })->with(['user:id,name', 'user.departments:id,name']);
        if ($scope === 'active') {
            $query->whereDate('period_start', '<=', $now->toDateString())->whereDate('period_end', '>=', $now->toDateString());
        } else {
            $query->whereDate('period_end', '<', $now->toDateString());
        }

        $category = $data['category'] ?? 'overall';
        $mode = $data['mode'] ?? 'actual';
        $rows = $query->orderByDesc('period_end')->get()->map(function (SalesTarget $target) use ($settings, $category, $mode, $canManage, $now) {
            $progress = $this->progress->progressForTarget($target, $now);
            $sales = $progress['sales_percentage'];
            $collection = $progress['collection_percentage'];
            $overall = $sales !== null && $collection !== null
                ? round(($sales * $settings['sales_weight'] / 100) + ($collection * $settings['collection_weight'] / 100), 2)
                : null;
            $expected = $this->progress->workingDayProgress($target);
            $score = match ($category) {
                'sales' => $sales,
                'collection' => $collection,
                default => $overall,
            };
            if ($mode === 'pace' && $score !== null) $score = $expected > 0 ? round(($score / $expected) * 100, 2) : null;
            $privacy = $canManage ? 'amounts' : $settings['data_visibility'];

            return [
                'target_id' => $target->id,
                'user_id' => $target->user_id,
                'employee_name' => $privacy === 'anonymous' ? null : $target->user?->name,
                'department' => $target->user?->departments->pluck('name')->join(', ') ?: null,
                'sales_percentage' => $sales,
                'collection_percentage' => $collection,
                'overall_score' => $overall,
                'ranking_score' => $score,
                'expected_progress' => $expected,
                'target_amount' => $privacy === 'amounts' ? $progress['target_amount'] : null,
                'receivable_amount' => $privacy === 'amounts' ? $progress['receivable_amount'] : null,
                'period_start' => $progress['period_start'],
                'period_end' => $progress['period_end'],
                'period_label' => $progress['period_label'],
                'period_status' => $progress['period_status'],
                'badges' => array_values(array_filter([
                    $overall !== null && $overall >= 100 ? 'Target Achieved' : null,
                    $overall !== null && $overall > 100 ? 'Target Exceeded' : null,
                ])),
            ];
        })->sort(function (array $a, array $b) {
            if ($a['ranking_score'] === null) return 1;
            if ($b['ranking_score'] === null) return -1;
            return $b['ranking_score'] <=> $a['ranking_score'];
        })->values();

        $rank = 0;
        $position = 0;
        $previousScore = null;
        $rows = $rows->map(function (array $row) use (&$rank, &$position, &$previousScore) {
            $position++;
            if ($row['ranking_score'] === null) {
                $row['rank'] = null;
                return $row;
            }
            if ($previousScore === null || $row['ranking_score'] !== $previousScore) $rank = $position;
            $row['rank'] = $rank;
            if ($rank === 1) $row['badges'][] = 'Top Performer';
            $previousScore = $row['ranking_score'];
            return $row;
        });

        $own = $rows->firstWhere('user_id', $request->user()->id);
        return response()->json(['data' => [
            'rows' => $rows,
            'category' => $category,
            'mode' => $mode,
            'period_scope' => $scope,
            'settings' => $settings,
            'own_rank' => $own,
        ]]);
    }

    public function getLeaderboardSettings(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden.'], 403);
        return response()->json(['data' => $this->leaderboardSettings($request->user()->organization)]);
    }

    public function updateLeaderboardSettings(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) return response()->json(['message' => 'Forbidden.'], 403);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(['everyone', 'management', 'disabled'])],
            'data_visibility' => ['required', Rule::in(['names_percentages', 'amounts', 'anonymous'])],
            'sales_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'collection_weight' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        if ((int) $data['sales_weight'] + (int) $data['collection_weight'] !== 100) {
            throw ValidationException::withMessages(['sales_weight' => 'Sales and collection weights must total 100%.']);
        }
        $org = $request->user()->organization;
        $settings = $org->settings ?? [];
        $settings['leaderboard'] = $data;
        $org->update(['settings' => $settings]);

        return response()->json(['data' => $data]);
    }

    private function targetPayload(SalesTarget $target): array
    {
        $progress = $this->progress->progressForTarget($target);
        return array_merge($progress, [
            'id' => $target->id,
            'user' => $target->user ? ['id' => $target->user->id, 'name' => $target->user->name] : null,
            'department' => $target->user?->departments?->pluck('name')->join(', ') ?: null,
            'notes' => $target->notes,
        ]);
    }

    private function history(int $orgId, int $userId): array
    {
        return SalesTarget::query()->where('organization_id', $orgId)->where('user_id', $userId)
            ->with(['user:id,name', 'user.departments:id,name'])->orderByDesc('period_start')->limit(12)->get()
            ->map(fn (SalesTarget $target) => $this->targetPayload($target))->values()->all();
    }

    private function overlapWindow(Builder $query, string $from, string $to): void
    {
        $query->whereDate('period_start', '<=', $to)
            ->where(function (Builder $period) use ($from) {
                $period->whereDate('period_end', '>=', $from)
                    ->orWhere(function (Builder $legacy) use ($from) {
                        $legacy->whereNull('period_end')->whereDate('period_start', '>=', Carbon::parse($from)->startOfMonth()->toDateString());
                    });
            });
    }

    private function leaderboardSettings(Organization $organization): array
    {
        return array_merge([
            'visibility' => 'management',
            'data_visibility' => 'names_percentages',
            'sales_weight' => 60,
            'collection_weight' => 40,
        ], ($organization->settings ?? [])['leaderboard'] ?? []);
    }
}
