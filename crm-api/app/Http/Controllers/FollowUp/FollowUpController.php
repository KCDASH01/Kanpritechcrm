<?php

namespace App\Http\Controllers\FollowUp;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUpResource;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    /** @var list<string> */
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $user  = $request->user();
        $isManager = $user->isAdmin() || $user->isOwner();

        $query = Activity::where('organization_id', $orgId)
            ->whereNotNull('due_at')
            ->where(function ($q) {
                foreach (self::LEAD_SUBJECT_TYPES as $type) {
                    $q->orWhere('subject_type', $type);
                }
            })
            ->with(['assignedTo']);

        if (! $isManager) {
            $query->where('assigned_to', $user->id);
        } elseif ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        } elseif ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($status = $request->input('status')) {
            match ($status) {
                'done'    => $query->where('is_done', true),
                'overdue' => $query->where('is_done', false)->where('due_at', '<', now()),
                'pending' => $query->where('is_done', false)->where('due_at', '>=', now()),
                default   => null,
            };
        }

        if ($due = $request->input('due')) {
            match ($due) {
                'today' => $query->where('is_done', false)
                    ->where('due_at', '>=', now())
                    ->where('due_at', '<=', now()->endOfDay()),
                'overdue' => $query->where('is_done', false)
                    ->where('due_at', '<', now()),
                default => null,
            };
        }

        if ($search = $request->input('search')) {
            $leadIds = Lead::where('organization_id', $orgId)
                ->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('company', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                })
                ->pluck('id');

            $query->whereIn('subject_id', $leadIds);
        }

        $query->orderBy('due_at', 'asc');

        $items = $query->paginate($request->input('per_page', 20));

        // Eager-load leads (subject_type is stored as 'lead' or App\Models\Lead)
        $leadIds = $items->getCollection()
            ->pluck('subject_id')
            ->filter()
            ->unique()
            ->values();

        $leads = Lead::where('organization_id', $orgId)
            ->whereIn('id', $leadIds)
            ->get()
            ->keyBy('id');

        $items->getCollection()->transform(function (Activity $activity) use ($leads) {
            $activity->setRelation('subject', $leads->get($activity->subject_id));

            return $activity;
        });

        return response()->json([
            'data' => FollowUpResource::collection($items),
            'meta' => [
                'total'        => $items->total(),
                'per_page'     => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
            ],
        ]);
    }
}
