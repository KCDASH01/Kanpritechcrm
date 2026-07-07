<?php

namespace App\Http\Controllers\EmailTemplate;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/email-templates ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $templates = EmailTemplate::where('organization_id', $this->orgId($request))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $templates]);
    }

    // ── POST /api/email-templates ─────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        if (!in_array($request->user()->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Only admins can create templates.'], 403);
        }

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:191'],
            'subject'       => ['required', 'string', 'max:191'],
            'body'          => ['required', 'string'],
            'stage_trigger' => ['nullable', 'string', 'max:191'],
        ]);

        $template = EmailTemplate::create(array_merge($data, [
            'organization_id' => $this->orgId($request),
        ]));

        return response()->json(['data' => $template], 201);
    }

    // ── PUT /api/email-templates/{template} ───────────────────────────────────

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        if ($emailTemplate->organization_id !== $this->orgId($request)) {
            abort(404);
        }

        if (!in_array($request->user()->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Only admins can edit templates.'], 403);
        }

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:191'],
            'subject'       => ['sometimes', 'string', 'max:191'],
            'body'          => ['sometimes', 'string'],
            'stage_trigger' => ['nullable', 'string', 'max:191'],
        ]);

        $emailTemplate->update($data);

        return response()->json(['data' => $emailTemplate]);
    }

    // ── DELETE /api/email-templates/{template} ────────────────────────────────

    public function destroy(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        if ($emailTemplate->organization_id !== $this->orgId($request)) {
            abort(404);
        }

        if (!in_array($request->user()->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Only admins can delete templates.'], 403);
        }

        $emailTemplate->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
