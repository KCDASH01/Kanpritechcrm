<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/employees ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = User::where('organization_id', $this->orgId($request))
                     ->where('id', '!=', $request->user()->id) // exclude self
                     ->where('is_active', true);

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        $employees = $query->with('departments')->orderBy('name')->get();

        return response()->json(['data' => UserResource::collection($employees)]);
    }

    // ── POST /api/employees ───────────────────────────────────────────────────

    public function store(EmployeeRequest $request): JsonResponse
    {
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
            'password'          => Hash::make($validated['password']),
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
        $this->authorizeOrg($request, $employee);

        $validated = $request->validated();
        $updates   = array_filter([
            'name'   => $validated['name'] ?? null,
            'email'  => $validated['email'] ?? null,
            'phone'  => $validated['phone'] ?? null,
            'avatar' => $validated['avatar'] ?? null,
            'role'   => $validated['role'] ?? null,
        ]);

        if (! empty($validated['password'])) {
            $updates['password'] = Hash::make($validated['password']);
        }

        $employee->update($updates);

        return response()->json([
            'data'    => new UserResource($employee->fresh()),
            'message' => 'Employee updated successfully.',
        ]);
    }

    // ── DELETE /api/employees/{employee} ──────────────────────────────────────

    public function destroy(Request $request, User $employee): JsonResponse
    {
        $this->authorizeOrg($request, $employee);

        if ($employee->role === 'owner') {
            return response()->json(['message' => 'Cannot remove the organization owner.'], 422);
        }

        // Revoke tokens and soft-delete
        $employee->tokens()->delete();
        $employee->delete();

        return response()->json(['message' => 'Employee removed successfully.']);
    }

    private function authorizeOrg(Request $request, User $employee): void
    {
        if ($employee->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }
}
