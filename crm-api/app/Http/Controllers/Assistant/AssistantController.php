<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\AssistantAlertService;
use App\Services\AssistantCollectionService;
use App\Services\TargetCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function alerts(Request $request, AssistantAlertService $alerts, AssistantCollectionService $collections): JsonResponse
    {
        $input = $request->validate([
            'include_collections' => ['sometimes', 'boolean'],
            'tracked_activity_ids' => ['sometimes', 'array', 'max:50'],
            'tracked_activity_ids.*' => ['integer', 'min:1'],
        ]);
        $viewer = $request->user();
        $result = ['data' => $alerts->importantAlerts($viewer)];
        if ($request->boolean('include_collections')) {
            $result['collections'] = $collections->reminders($viewer);
            $result['completed_activity_ids'] = $alerts->completedActivityIds($viewer, $input['tracked_activity_ids'] ?? []);
        }

        return response()->json($result);
    }

    public function targets(Request $request, TargetCoachService $coach): JsonResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $coach->summaries($request->user(), isset($data['user_id']) ? (int) $data['user_id'] : null)]);
    }
}
