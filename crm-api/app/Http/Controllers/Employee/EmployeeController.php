<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Http\Resources\UserResource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(private readonly LeadOwnershipService $ownership) {}

    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/employees ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->where('organization_id', $this->orgId($request))
                     ->where('id', '!=', $request->user()->id) // exclude self
                     ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true));

        if ($request->boolean('include_inactive')) {
            $this->authorizeManager($request);
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        $employees = $query->with('departments')->orderBy('name')->get();

        return response()->json(['data' => UserResource::collection($employees)]);
    }

    // ── POST /api/employees ───────────────────────────────────────────────────

    public function store(EmployeeRequest $request): JsonResponse
    {
        $this->authorizeManager($request);
        $validated = $request->validated();

        // Seat limit check — count non-owner, non-deleted team members
        $org   = $request->user()->organization;
        $limit = $org?->totalTeamMemberLimit() ?? 0;

        if ($limit > 0) {
            $currentCount = User::where('organization_id', $this->orgId($request))
                ->where('role', '!=', 'owner')
                ->whereNull('deleted_at')
                ->count();

            if ($currentCount >= $limit) {
                return response()->json([
                    'message' => "Team member limit reached ({$limit} seats). Purchase extra seats on the Billing page.",
                    'code'    => 'SEAT_LIMIT_REACHED',
                ], 403);
            }
        }

        $employee = User::create([
            'organization_id'   => $this->orgId($request),
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => $validated['password'], // hashed cast on User model
            'phone'             => $validated['phone'] ?? null,
            'avatar'            => $validated['avatar'] ?? null,
            'role'              => $validated['role'] ?? 'employee',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'data'    => new UserResource($employee),
            'message' => 'Employee added successfully.',
        ], 201);
    }

    // ── GET /api/employees/{employee} ─────────────────────────────────────────

    public function show(Request $request, User $employee): JsonResponse
    {
        $this->authorizeOrg($request, $employee);

        return response()->json([
            'data' => new UserResource($employee->load('departments')),
        ]);
    }

    // ── PUT /api/employees/{employee} ─────────────────────────────────────────

    public function update(EmployeeRequest $request, User $employee): JsonResponse
    {
        $this->authorizeManager($request);
        $this->authorizeOrg($request, $employee);

        $validated = $request->validated();
        $updates   = [];

        foreach (['name', 'email', 'phone', 'avatar', 'role'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $updates[$field] = $validated[$field];
            }
        }

        // Pass plain password — User model `hashed` cast hashes once.
        if (! empty($validated['password'])) {
            $updates['password'] = $validated['password'];
        }

        if ($updates === []) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $employee->update($updates);

        return response()->json([
            'data'    => new UserResource($employee->fresh()),
            'message' => ! empty($validated['password']) && count($updates) === 1
                ? 'Password updated successfully.'
                : 'Employee updated successfully.',
        ]);
    }

    // ── DELETE /api/employees/{employee} ──────────────────────────────────────

    public function removalPreview(Request $request, User $employee): JsonResponse
    {
        $this->authorizeManager($request);
        $this->authorizeOrg($request, $employee);

        $leadQuery = Lead::query()
            ->where('organization_id', $this->orgId($request))
            ->where('assigned_to', $employee->id);

        return response()->json(['data' => [
            'employee_id' => $employee->id,
            'assigned_leads' => (clone $leadQuery)->count(),
            'active_follow_ups' => Activity::query()
                ->where('organization_id', $this->orgId($request))
                ->whereIn('subject_type', ['lead', Lead::class])
                ->whereIn('subject_id', $leadQuery->select('id'))
                ->where('type', 'task')
                ->whereNotNull('due_at')
                ->where('is_done', false)
                ->count(),
        ]]);
    }

    public function destroy(Request $request, User $employee): JsonResponse
    {
        $this->authorizeManager($request);
        $this->authorizeOrg($request, $employee);

        if ($employee->role === 'owner') {
            return response()->json(['message' => 'Cannot remove the organization owner.'], 422);
        }

        $data = $this->validateOwnershipAction($request, $employee);

        $changed = DB::transaction(function () use ($request, $employee, $data) {
            $lockedEmployee = User::query()->lockForUpdate()->findOrFail($employee->id);
            $target = $this->transferTarget($request, $lockedEmployee, $data);
            $leads = Lead::query()
                ->where('organization_id', $this->orgId($request))
                ->where('assigned_to', $lockedEmployee->id)
                ->lockForUpdate()
                ->get();

            $changed = $this->ownership->reassignLocked(
                $leads,
                $target,
                $request->user(),
                'employee_removal',
                $data['reason'] ?? 'Employee removed',
                $lockedEmployee->id,
            );

            $lockedEmployee->tokens()->delete();
            $lockedEmployee->forceFill(['is_active' => false])->save();
            $lockedEmployee->delete();

            return $changed;
        });

        return response()->json([
            'message' => 'Employee removed successfully.',
            'reassigned_leads' => $changed,
        ]);
    }

    public function deactivate(Request $request, User $employee): JsonResponse
    {
        $this->authorizeManager($request);
        $this->authorizeOrg($request, $employee);
        if ($employee->role === 'owner') {
            return response()->json(['message' => 'Cannot deactivate the organization owner.'], 422);
        }

        $data = $this->validateOwnershipAction($request, $employee);
        $changed = DB::transaction(function () use ($request, $employee, $data) {
            $lockedEmployee = User::query()->lockForUpdate()->findOrFail($employee->id);
            $target = $this->transferTarget($request, $lockedEmployee, $data);
            $leads = Lead::query()->where('organization_id', $this->orgId($request))
                ->where('assigned_to', $lockedEmployee->id)->lockForUpdate()->get();
            $changed = $this->ownership->reassignLocked(
                $leads, $target, $request->user(), 'employee_deactivation',
                $data['reason'] ?? 'Employee deactivated', $lockedEmployee->id,
            );
            $lockedEmployee->tokens()->delete();
            $lockedEmployee->update(['is_active' => false]);
            return $changed;
        });

        return response()->json(['message' => 'Employee deactivated successfully.', 'reassigned_leads' => $changed]);
    }

    public function reactivate(Request $request, int $employee): JsonResponse
    {
        $this->authorizeManager($request);
        $member = User::withTrashed()->where('organization_id', $this->orgId($request))->findOrFail($employee);
        if ($member->trashed()) {
            return response()->json(['message' => 'A removed employee cannot be reactivated.'], 422);
        }
        $member->update(['is_active' => true]);

        return response()->json(['data' => new UserResource($member->fresh()), 'message' => 'Employee reactivated successfully.']);
    }

    private function authorizeOrg(Request $request, User $employee): void
    {
        if ($employee->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only owners and administrators can manage team members.');
    }

    private function validateOwnershipAction(Request $request, User $employee): array
    {
        return $request->validate([
            'lead_action' => ['sometimes', Rule::in(['transfer', 'unassign'])],
            'transfer_to' => [
                Rule::requiredIf($request->input('lead_action') === 'transfer'),
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('organization_id', $this->orgId($request))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $employee->id)),
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function transferTarget(Request $request, User $employee, array $data): ?User
    {
        if (($data['lead_action'] ?? 'unassign') !== 'transfer') {
            return null;
        }

        return User::query()
            ->where('organization_id', $this->orgId($request))
            ->where('is_active', true)
            ->where('id', '!=', $employee->id)
            ->lockForUpdate()
            ->findOrFail($data['transfer_to']);
    }
}
