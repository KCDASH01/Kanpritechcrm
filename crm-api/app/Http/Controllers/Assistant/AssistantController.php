<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\AssistantAlertService;
use App\Services\TargetCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function alerts(Request $request, AssistantAlertService $alerts): JsonResponse
    {
        return response()->json(['data' => $alerts->importantAlerts($request->user())]);
    }

    public function targets(Request $request, TargetCoachService $coach): JsonResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $coach->summaries($request->user(), isset($data['user_id']) ? (int) $data['user_id'] : null)]);
    }
}
