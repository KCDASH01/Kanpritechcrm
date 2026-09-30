<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\PipelineRequest;
use App\Http\Resources\PipelineResource;
use App\Models\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/pipelines ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $pipelines = Pipeline::where('organization_id', $this->orgId($request))
                             ->with('stages')
                             ->orderBy('sort_order')
                             ->get();

        return response()->json(['data' => PipelineResource::collection($pipelines)]);
    }

    // ── POST /api/pipelines ───────────────────────────────────────────────────

    public function store(PipelineRequest $request): JsonResponse
    {
        // Plan pipeline limits
        $org = $request->user()->organization;
        if ($org?->isEnterprisePlan()) {
            // Enterprise: unlimited — no check
        } elseif ($org?->isBusinessPlan()) {
            $count = Pipeline::where('organization_id', $this->orgId($request))->count();
            if ($count >= 30) {
                return response()->json([
                    'message' => 'You have reached the 30-pipeline limit on the Business plan. Upgrade to Enterprise for unlimited pipelines.',
                    'code'    => 'LIMIT_REACHED',
                ], 403);
            }
        } else {
            $count = Pipeline::where('organization_id', $this->orgId($request))->count();
            if ($count >= 1) {
                return response()->json([
                    'message' => 'Free plan allows only 1 pipeline. Upgrade to Business or Enterprise.',
                    'code'    => 'LIMIT_REACHED',
                ], 403);
            }
        }

        $validated = $request->validated();

        if (($validated['is_default'] ?? false) === true) {
            Pipeline::where('organization_id', $this->orgId($request))->update(['is_default' => false]);
        }

        $pipeline = Pipeline::create(array_merge($validated, [
            'organization_id' => $this->orgId($request),
        ]));

        return response()->json([
            'data'    => new PipelineResource($pipeline->load('stages')),
            'message' => 'Pipeline created successfully.',
        ], 201);
    }

    // ── GET /api/pipelines/{pipeline} ─────────────────────────────────────────

    public function show(Request $request, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeOrg($request, $pipeline);

        return response()->json([
            'data' => new PipelineResource($pipeline->load('stages')),
        ]);
    }

    // ── PUT /api/pipelines/{pipeline} ─────────────────────────────────────────

    public function update(PipelineRequest $request, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeOrg($request, $pipeline);

        $validated = $request->validated();

        if (($validated['is_default'] ?? false) === true) {
            Pipeline::where('organization_id', $pipeline->organization_id)
                ->where('id', '!=', $pipeline->id)
                ->update(['is_default' => false]);
        }

        $pipeline->update($validated);

        return response()->json([
            'data'    => new PipelineResource($pipeline->fresh('stages')),
            'message' => 'Pipeline updated successfully.',
        ]);
    }

    // ── DELETE /api/pipelines/{pipeline} ──────────────────────────────────────

    public function destroy(Request $request, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeOrg($request, $pipeline);

        $remaining = Pipeline::where('organization_id', $this->orgId($request))
            ->where('id', '!=', $pipeline->id)
            ->count();

        if ($remaining === 0) {
            return response()->json(['message' => 'Cannot delete the only remaining pipeline.'], 422);
        }

        if ($pipeline->is_default) {
            return response()->json(['message' => 'Cannot delete the default pipeline. Set another pipeline as default first.'], 422);
        }

        $pipeline->delete();

        return response()->json(['message' => 'Pipeline deleted successfully.']);
    }

    private function authorizeOrg(Request $request, Pipeline $pipeline): void
    {
        if ($pipeline->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }
}
