<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssistantSoundSettingsController extends Controller
{
    private const DEFAULTS = [
        'enabled' => true,
        'success' => true,
        'alert' => true,
        'celebrate' => true,
        'volume' => 'low',
        'play_when_hidden' => false,
        'buddy_interval_seconds' => 60,
        'coach_interval_seconds' => 60,
    ];

    public function show(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        return response()->json(['data' => array_replace(self::DEFAULTS, $organization->settings['assistant_sound'] ?? [])]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() && $user->organization_id, 403);

        $settings = $request->validate([
            'enabled' => ['required', 'boolean'],
            'success' => ['required', 'boolean'],
            'alert' => ['required', 'boolean'],
            'celebrate' => ['required', 'boolean'],
            'volume' => ['required', 'in:low,medium,high'],
            'play_when_hidden' => ['required', 'boolean'],
            'buddy_interval_seconds' => ['required', 'integer', 'between:15,3600'],
            'coach_interval_seconds' => ['required', 'integer', 'between:15,3600'],
            'organization_id' => ['missing'],
        ]);

        foreach (['enabled', 'success', 'alert', 'celebrate', 'play_when_hidden'] as $field) {
            $settings[$field] = (bool) $settings[$field];
        }
        foreach (['buddy_interval_seconds', 'coach_interval_seconds'] as $field) {
            $settings[$field] = (int) $settings[$field];
        }

        DB::transaction(function () use ($user, $settings) {
            $organization = Organization::query()->lockForUpdate()->findOrFail($user->organization_id);
            $current = $organization->settings ?? [];
            $current['assistant_sound'] = $settings;
            $organization->update(['settings' => $current]);
        });

        return response()->json(['data' => $settings]);
    }
}
