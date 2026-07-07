<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function orgId(Request $request): int
    {
        return $request->user()->organization_id;
    }

    // ── GET /api/notifications ────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('organization_id', $this->orgId($request))
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'meta' => [
                'total'        => $notifications->total(),
                'per_page'     => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
            ],
        ]);
    }

    // ── GET /api/notifications/unread-count ───────────────────────────────────

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('organization_id', $this->orgId($request))
            ->where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    // ── PATCH /api/notifications/{notification}/read ──────────────────────────

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->organization_id !== $this->orgId($request) ||
            $notification->user_id !== $request->user()->id) {
            abort(404);
        }

        $notification->markRead();

        return response()->json([
            'data'    => new NotificationResource($notification->fresh()),
            'message' => 'Notification marked as read.',
        ]);
    }

    // ── POST /api/notifications/read-all ─────────────────────────────────────

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::where('organization_id', $this->orgId($request))
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
