<?php

namespace App\Http\Controllers\Department;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/departments ──────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $departments = Department::where('organization_id', $this->orgId($request))
                                 ->withCount('users')
                                 ->with('users')
                                 ->get();

        return response()->json(['data' => DepartmentResource::collection($departments)]);
    }

    // ── POST /api/departments ─────────────────────────────────────────────────

    public function store(DepartmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $department = Department::create([
            'organization_id' => $this->orgId($request),
            'name'            => $validated['name'],
            'description'     => $validated['description'] ?? null,
        ]);

        if (! empty($validated['member_ids'])) {
            $department->users()->sync($validated['member_ids']);
        }

        return response()->json([
            'data'    => new DepartmentResource($department->load('users')),
            'message' => 'Department created successfully.',
        ], 201);
    }

    // ── GET /api/departments/{department} ─────────────────────────────────────

    public function show(Request $request, Department $department): JsonResponse
    {
        $this->authorizeOrg($request, $department);

        return response()->json([
            'data' => new DepartmentResource($department->load('users')),
        ]);
    }

    // ── PUT /api/departments/{department} ─────────────────────────────────────

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorizeOrg($request, $department);

        $validated = $request->validated();

        $department->update([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? $department->description,
        ]);

        if (isset($validated['member_ids'])) {
            $department->users()->sync($validated['member_ids']);
        }

        return response()->json([
            'data'    => new DepartmentResource($department->fresh('users')),
            'message' => 'Department updated successfully.',
        ]);
    }

    // ── DELETE /api/departments/{department} ──────────────────────────────────

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->authorizeOrg($request, $department);

        $department->delete();

        return response()->json(['message' => 'Department deleted successfully.']);
    }

    private function authorizeOrg(Request $request, Department $department): void
    {
        if ($department->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }
}
