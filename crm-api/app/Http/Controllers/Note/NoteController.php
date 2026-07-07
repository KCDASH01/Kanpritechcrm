<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Http\Requests\Note\NoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    private const MORPH_MAP = [
        'lead' => \App\Models\Lead::class,
        'deal' => \App\Models\Deal::class,
    ];

    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/notes?notable_type=lead&notable_id=5 ─────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Note::where('organization_id', $this->orgId($request))
                     ->with('createdBy');

        if ($notableType = $request->input('notable_type')) {
            $morphClass = self::MORPH_MAP[$notableType] ?? null;
            if ($morphClass) {
                $query->where('notable_type', $morphClass);
            }
        }

        if ($notableId = $request->input('notable_id')) {
            $query->where('notable_id', $notableId);
        }

        $notes = $query->orderByDesc('is_pinned')->orderByDesc('created_at')->get();

        return response()->json(['data' => NoteResource::collection($notes)]);
    }

    // ── POST /api/notes ───────────────────────────────────────────────────────

    public function store(NoteRequest $request): JsonResponse
    {
        $validated  = $request->validated();
        $morphClass = self::MORPH_MAP[$validated['notable_type']];

        $note = Note::create([
            'organization_id' => $this->orgId($request),
            'created_by'      => $request->user()->id,
            'notable_type'    => $morphClass,
            'notable_id'      => $validated['notable_id'],
            'content'         => $validated['content'],
            'is_pinned'       => $validated['is_pinned'] ?? false,
        ]);

        return response()->json([
            'data'    => new NoteResource($note->load('createdBy')),
            'message' => 'Note created successfully.',
        ], 201);
    }

    // ── PUT /api/notes/{note} ─────────────────────────────────────────────────

    public function update(Request $request, Note $note): JsonResponse
    {
        $this->authorizeOrg($request, $note);

        $data = $request->validate([
            'content'   => ['sometimes', 'string'],
            'is_pinned' => ['boolean'],
        ]);

        $note->update($data);

        return response()->json([
            'data'    => new NoteResource($note->fresh('createdBy')),
            'message' => 'Note updated successfully.',
        ]);
    }

    // ── DELETE /api/notes/{note} ──────────────────────────────────────────────

    public function destroy(Request $request, Note $note): JsonResponse
    {
        $this->authorizeOrg($request, $note);

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully.']);
    }

    private function authorizeOrg(Request $request, Note $note): void
    {
        if ($note->organization_id !== $this->orgId($request)) {
            abort(404);
        }
    }
}
