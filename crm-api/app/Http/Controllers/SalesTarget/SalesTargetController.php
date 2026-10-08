<?php

namespace App\Http\Controllers\SalesTarget;

use App\Http\Controllers\Controller;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\TargetProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SalesTargetController extends Controller
{
    public function __construct(private readonly TargetProgressService $progress) {}

    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/sales-targets?month=YYYY-MM ─────────────────────────────────
    // Managers: all org users with actuals; team members: own targets only

    public function index(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $orgId      = $this->orgId($request);
        $user       = $request->user();
        $canManage  = in_array($user->role, ['owner', 'admin']);
        $monthParam = $request->input('month', now()->format('Y-m'));

        [$periodStart, $periodEnd] = $this->progress->period($monthParam);

        $query = SalesTarget::where('organization_id', $orgId)
            ->whereDate('period_start', $periodStart)
            ->whereHas('user', fn ($member) => $member->where('is_active', true)->where('role', '!=', 'owner'))
            ->with('user:id,name,email');

        if (!$canManage) {
            $query->where('user_id', $user->id);
        }

        $rows = $query->get()->map(function (SalesTarget $t) use ($orgId, $periodStart, $periodEnd) {
            $achieved  = $this->progress->achieved($orgId, (int)$t->user_id, $periodStart, $periodEnd);
            $received  = $this->progress->received($orgId, (int)$t->user_id, $periodStart, $periodEnd);
            return [
                'id'                => $t->id,
                'user'              => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
                'target_amount'     => (float) $t->target_amount,
                'receivable_amount' => (float) $t->receivable_amount,
                'received_amount'   => $received,
                'achieved_amount'   => $achieved,
                'notes'             => $t->notes,
                'period_start'      => $t->period_start->toDateString(),
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    // ── POST /api/sales-targets — upsert monthly targets for an employee ───────

    public function upsert(Request $request): JsonResponse
    {
        if (!in_array($request->user()->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Only managers can set targets.'], 403);
        }

        $data = $request->validate([
            'user_id'           => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->where('organization_id', $this->orgId($request))
                ->where('is_active', true)
                ->where('role', '!=', 'owner'))],
            'target_amount'     => ['required', 'numeric', 'min:0'],
            'receivable_amount' => ['required', 'numeric', 'min:0'],
            'period_start'      => ['required', 'date'],
        ]);

        $periodStart = Carbon::parse($data['period_start'])->startOfMonth();

        // SQLite stores Eloquent date casts with a time component. Match on the
        // calendar date so an existing member/month target is updated instead
        // of attempting a duplicate insert against the unique constraint.
        $target = SalesTarget::query()
            ->where('organization_id', $this->orgId($request))
            ->where('user_id', $data['user_id'])
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if (! $target) {
            $target = new SalesTarget([
                'organization_id' => $this->orgId($request),
                'user_id'         => $data['user_id'],
                'period_start'    => $periodStart,
            ]);
        }

        $target->fill([
            'target_amount'     => $data['target_amount'],
            'receivable_amount' => $data['receivable_amount'],
        ])->save();

        return response()->json(['data' => $target]);
    }

    // ── PATCH /api/sales-targets/{salesTarget}/received ──────────────────────
    // Admin OR the row's own user can update received_amount

    public function updateReceived(Request $request, SalesTarget $salesTarget): JsonResponse
    {
        $user = $request->user();
        $canManage = in_array($user->role, ['owner', 'admin']);

        if ($salesTarget->organization_id !== $this->orgId($request)) abort(404);

        if (!$canManage && $salesTarget->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'received_amount' => ['required', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $salesTarget->update($data);

        return response()->json(['data' => $salesTarget->fresh()]);
    }

    // ── GET /api/sales-targets/my-progress?month=YYYY-MM ─────────────────────
    // Returns last 6 months of targets for the authenticated user with achieved_amount

    public function myProgress(Request $request): JsonResponse
    {
        $orgId  = $this->orgId($request);
        $userId = $request->user()->id;

        // Build array of last 6 months (period_start values)
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->startOfMonth()->toDateString();
        }

        $targets = SalesTarget::where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->whereDate('period_start', '>=', $months[0])
            ->whereDate('period_start', '<=', $months[count($months) - 1])
            ->orderBy('period_start')
            ->get();

        // Index by period_start for easy lookup
        $indexed = $targets->keyBy(fn($t) => $t->period_start->toDateString());

        $progress = array_map(function (string $periodStart) use ($orgId, $userId, $indexed) {
            $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();
            $target    = $indexed->get($periodStart);
            $achieved  = $this->progress->achieved($orgId, $userId, $periodStart, $periodEnd);
            $received  = $this->progress->received($orgId, $userId, $periodStart, $periodEnd);

            return [
                'period_start'      => $periodStart,
                'target_amount'     => $target ? (float) $target->target_amount : 0,
                'receivable_amount' => $target ? (float) $target->receivable_amount : 0,
                'received_amount'   => $received,
                'achieved_amount'   => $achieved,
                'target_id'         => $target?->id,
            ];
        }, $months);

        return response()->json(['data' => $progress]);
    }

    // ── GET /api/sales-targets/{user}/progress ────────────────────────────────
    // Owner/admin: returns last 6 months of targets for any org user with achieved_amount

    public function userProgress(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();

        // Only owners and admins can view another user's progress
        if (!in_array($authUser->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Must be in the same org
        if ($user->organization_id !== $authUser->organization_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $orgId  = $user->organization_id;
        $userId = $user->id;

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->startOfMonth()->toDateString();
        }

        $targets = SalesTarget::where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->whereDate('period_start', '>=', $months[0])
            ->whereDate('period_start', '<=', $months[count($months) - 1])
            ->orderBy('period_start')
            ->get();

        $indexed = $targets->keyBy(fn($t) => $t->period_start->toDateString());

        $progress = array_map(function (string $periodStart) use ($orgId, $userId, $indexed) {
            $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();
            $target    = $indexed->get($periodStart);
            $achieved  = $this->progress->achieved($orgId, $userId, $periodStart, $periodEnd);
            $received  = $this->progress->received($orgId, $userId, $periodStart, $periodEnd);

            return [
                'period_start'      => $periodStart,
                'target_amount'     => $target ? (float) $target->target_amount : 0,
                'receivable_amount' => $target ? (float) $target->receivable_amount : 0,
                'received_amount'   => $received,
                'achieved_amount'   => $achieved,
                'target_id'         => $target?->id,
            ];
        }, $months);

        return response()->json([
            'data' => $progress,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

}
