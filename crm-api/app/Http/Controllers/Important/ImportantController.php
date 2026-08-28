<?php

namespace App\Http\Controllers\Important;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportantResource;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportantController extends Controller
{
    /** @var list<string> */
    private const LEAD_SUBJECT_TYPES = ['lead', Lead::class];

    public function index(Request $request): JsonResponse
    {
        $orgId     = $request->user()->organization_id;
        $user      = $request->user();
        $isManager = $user->isAdmin() || $user->isOwner();

        $query = Lead::where('organization_id', $orgId)
            ->where('status', 'important')
            ->with(['assignedTo']);

        if (! $isManager) {
            $query->where('assigned_to', $user->id);
        } elseif ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        } elseif ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($types = $request->input('types')) {
            $query->where('types', $types);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('lead_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('lead_date', '<=', $dateTo);
        }

        if ($status = $request->input('status')) {
            $this->applyImportantActivityExists($query, function ($q) use ($status) {
                match ($status) {
                    'done'    => $q->where('a.is_done', true),
                    'pending' => $q->where('a.is_done', false),
                    default   => null,
                };
            });
        }

        $query->orderByDesc('updated_at');

        $leads = $query->paginate($request->input('per_page', 20));

        $leadIds = $leads->getCollection()->pluck('id');

        $activities = Activity::whereIn('subject_id', $leadIds)
            ->where(function ($q) {
                foreach (self::LEAD_SUBJECT_TYPES as $type) {
                    $q->orWhere('subject_type', $type);
                }
            })
            ->where('type', 'note')
            ->where('title', 'like', 'Important%')
            ->orderBy('is_done')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get()
            ->unique('subject_id')
            ->keyBy('subject_id');

        $leads->getCollection()->transform(function (Lead $lead) use ($activities) {
            $lead->setRelation('importantActivity', $activities->get($lead->id));

            return $lead;
        });

        return response()->json([
            'data' => ImportantResource::collection($leads),
            'meta' => [
                'total'        => $leads->total(),
                'per_page'     => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page'    => $leads->lastPage(),
            ],
        ]);
    }

    /** @param callable(\Illuminate\Database\Query\Builder): void $constraints */
    private function applyImportantActivityExists(Builder $query, callable $constraints): void
    {
        $subjectTypes = self::LEAD_SUBJECT_TYPES;
        $leadClass    = Lead::class;

        $query->whereExists(function ($sub) use ($constraints, $subjectTypes, $leadClass) {
            $sub->selectRaw('1')
                ->from('activities as a')
                ->whereColumn('a.subject_id', 'leads.id')
                ->where(function ($q) use ($subjectTypes) {
                    foreach ($subjectTypes as $type) {
                        $q->orWhere('a.subject_type', $type);
                    }
                })
                ->where('a.type', 'note')
                ->where('a.title', 'like', 'Important%')
                ->whereNull('a.deleted_at')
                ->whereRaw(
                    "a.id = (
                        SELECT a2.id FROM activities a2
                        WHERE a2.subject_id = leads.id
                          AND a2.type = 'note'
                          AND a2.title LIKE 'Important%'
                          AND a2.deleted_at IS NULL
                          AND (a2.subject_type = ? OR a2.subject_type = ?)
                        ORDER BY a2.is_done ASC, a2.updated_at DESC, a2.created_at DESC
                        LIMIT 1
                    )",
                    [$subjectTypes[0], $leadClass]
                );

            $constraints($sub);
        });
    }
}
