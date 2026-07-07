<?php

namespace App\Http\Controllers\Proposal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Proposal;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProposalController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    private function authorizeOrg(Request $request, object $model): void
    {
        if ($model->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }

    // ── POST /api/leads/{lead}/proposals/generate ─────────────────────────────

    public function generate(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        $data = $request->validate([
            'notes' => ['required', 'string', 'min:20'],
            'theme' => ['required', 'in:modern,corporate,minimal,vibrant'],
        ]);

        $user = $request->user();

        // Load lead relations for prompt context
        $lead->load(['stage', 'pipeline']);

        try {
            $aiService = new AIService();
            $content   = $aiService->generateProposal($lead->toArray(), $data['notes'], $data['theme']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $proposal = Proposal::create([
            'organization_id'    => $lead->organization_id,
            'lead_id'            => $lead->id,
            'created_by'         => $user->id,
            'title'              => $content['title'] ?? "Proposal for {$lead->full_name}",
            'theme'              => $data['theme'],
            'conversation_notes' => $data['notes'],
            'content'            => $content,
            'status'             => 'draft',
            'valid_until'        => now()->addDays($content['validity_days'] ?? 30)->toDateString(),
        ]);

        return response()->json([
            'data'    => $this->formatProposal($proposal->load('createdBy')),
            'message' => 'Proposal generated successfully.',
        ], 201);
    }

    // ── GET /api/leads/{lead}/proposals ──────────────────────────────────────

    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeOrg($request, $lead);

        $proposals = Proposal::where('lead_id', $lead->id)
            ->where('organization_id', $this->orgId($request))
            ->with('createdBy')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $proposals->map(fn($p) => $this->formatProposal($p)),
        ]);
    }

    // ── GET /api/proposals/{proposal} ────────────────────────────────────────

    public function show(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizeOrg($request, $proposal);

        return response()->json([
            'data' => $this->formatProposal($proposal->load('createdBy')),
        ]);
    }

    // ── PUT /api/proposals/{proposal} ────────────────────────────────────────

    public function update(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizeOrg($request, $proposal);

        $data = $request->validate([
            'content'     => ['sometimes', 'array'],
            'status'      => ['sometimes', 'in:draft,sent,accepted,rejected'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'title'       => ['sometimes', 'string', 'max:255'],
            'theme'       => ['sometimes', 'in:modern,corporate,minimal,vibrant'],
        ]);

        $proposal->update($data);

        return response()->json([
            'data'    => $this->formatProposal($proposal->fresh('createdBy')),
            'message' => 'Proposal updated successfully.',
        ]);
    }

    // ── DELETE /api/proposals/{proposal} ─────────────────────────────────────

    public function destroy(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizeOrg($request, $proposal);

        $proposal->delete();

        return response()->json(['message' => 'Proposal deleted successfully.']);
    }

    private function formatProposal(Proposal $proposal): array
    {
        return [
            'id'                 => $proposal->id,
            'lead_id'            => $proposal->lead_id,
            'title'              => $proposal->title,
            'theme'              => $proposal->theme,
            'conversation_notes' => $proposal->conversation_notes,
            'content'            => $proposal->content,
            'status'             => $proposal->status,
            'valid_until'        => $proposal->valid_until?->toDateString(),
            'created_by'         => $proposal->createdBy ? [
                'id'   => $proposal->createdBy->id,
                'name' => $proposal->createdBy->name,
            ] : null,
            'created_at' => $proposal->created_at->toIso8601String(),
            'updated_at' => $proposal->updated_at->toIso8601String(),
        ];
    }
}
