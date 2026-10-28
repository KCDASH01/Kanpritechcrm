<?php

namespace App\Http\Controllers\LeadIntegration;

use App\Http\Controllers\Controller;
use App\Services\LeadIntegrations\WhatsAppEmbeddedSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppEmbeddedSignupController extends Controller
{
    public function start(Request $request, WhatsAppEmbeddedSignupService $service): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => $service->start($request->user())]);
    }

    public function complete(Request $request, WhatsAppEmbeddedSignupService $service): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'session_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:4096'],
            'waba_id' => ['required', 'string', 'max:191', 'regex:/^\d+$/'],
            'phone_number_id' => ['required', 'string', 'max:191', 'regex:/^\d+$/'],
        ]);
        $connection = $service->complete($request->user(), $data);

        return response()->json([
            'message' => 'WhatsApp Business connected and webhook subscription verified.',
            'data' => $connection,
        ], 201);
    }
}
