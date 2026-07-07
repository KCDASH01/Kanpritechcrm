<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\StageResource;
use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── POST /api/pipelines/{pipeline}/stages ──────────────────────────────────

    public function store(Request $request, Pipeline $pipeline): JsonResponse
    {
        $this->authorizePipeline($request, $pipeline);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:191'],
            'color'       => ['nullable', 'string', 'max:20'],
            'sort_order'  => ['nullable', 'integer'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_won'      => ['nullable', 'boolean'],
            'is_lost'     => ['nullable', 'boolean'],
        ]);

        $data = $this->normalizeStageFlags($data);

        $stage = $pipeline->stages()->create(array_merge($data, [
            'organization_id' => $pipeline->organization_id,
        ]));

        return response()->json([
            'data'    => new StageResource($stage),
            'message' => 'Stage created successfully.',
        ], 201);
    }

    // ── PUT /api/pipelines/{pipeline}/stages/{stage} ──────────────────────────

    public function update(Request $request, Pipeline $pipeline, Stage $stage): JsonResponse
    {
        $this->authorizePipeline($request, $pipeline);
        $this->authorizeStage($pipeline, $stage);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:191'],
            'color'       => ['nullable', 'string', 'max:20'],
            'sort_order'  => ['nullable', 'integer'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_won'      => ['nullable', 'boolean'],
            'is_lost'     => ['nullable', 'boolean'],
        ]);

        $stage->update($this->normalizeStageFlags($data));

        return response()->json([
            'data'    => new StageResource($stage->fresh()),
            'message' => 'Stage updated successfully.',
        ]);
    }

    // ── DELETE /api/pipelines/{pipeline}/stages/{stage} ───────────────────────

    public function destroy(Request $request, Pipeline $pipeline, Stage $stage): JsonResponse
    {
        $this->authorizePipeline($request, $pipeline);
        $this->authorizeStage($pipeline, $stage);

        $stage->delete();

        return response()->json(['message' => 'Stage deleted successfully.']);
    }

    // ── POST /api/pipelines/{pipeline}/stages/reorder ─────────────────────────

    public function reorder(Request $request, Pipeline $pipeline): JsonResponse
    {
        $this->authorizePipeline($request, $pipeline);

        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($request->input('order') as $sortOrder => $stageId) {
            Stage::where('id', $stageId)
                 ->where('pipeline_id', $pipeline->id)
                 ->update(['sort_order' => $sortOrder]);
        }

        return response()->json([
            'data'    => StageResource::collection($pipeline->stages()->orderBy('sort_order')->get()),
            'message' => 'Stages reordered successfully.',
        ]);
    }

    private function authorizePipeline(Request $request, Pipeline $pipeline): void
    {
        if ($pipeline->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }

    private function authorizeStage(Pipeline $pipeline, Stage $stage): void
    {
        if ($stage->pipeline_id !== $pipeline->id) {
            abort(404);
        }
    }

    /** Ensure is_won and is_lost are mutually exclusive; default both false = open stage. */
    private function normalizeStageFlags(array $data): array
    {
        $isWon  = (bool) ($data['is_won'] ?? false);
        $isLost = (bool) ($data['is_lost'] ?? false);

        if ($isWon && $isLost) {
            $isLost = false;
        }

        $data['is_won']  = $isWon;
        $data['is_lost'] = $isLost;

        return $data;
    }
}
