<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\LeadResource;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:191'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $employeeId = $request->user()->isEmployee() ? $request->user()->id : null;
        $query = $this->visibleQuery($request)
            ->with('assignedTo:id,name,email')
            ->withCount([
                'leads' => fn ($q) => $q->when($employeeId, fn ($visible) => $visible->where('assigned_to', $employeeId)),
                'deals' => fn ($q) => $q->when($employeeId, fn ($visible) => $visible->where('assigned_to', $employeeId)),
            ]);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $clients = $query->orderByRaw("COALESCE(NULLIF(company, ''), first_name) asc")->paginate($request->input('per_page', 20));
        return response()->json([
            'data' => ClientResource::collection($clients),
            'meta' => ['total' => $clients->total(), 'per_page' => $clients->perPage(), 'current_page' => $clients->currentPage(), 'last_page' => $clients->lastPage()],
        ]);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->authorizeVisible($request, $client);
        $employeeId = $request->user()->isEmployee() ? $request->user()->id : null;
        $client->load(['assignedTo:id,name,email']);
        $client->loadCount([
            'leads' => fn ($q) => $q->when($employeeId, fn ($visible) => $visible->where('assigned_to', $employeeId)),
            'deals' => fn ($q) => $q->when($employeeId, fn ($visible) => $visible->where('assigned_to', $employeeId)),
        ]);
        $leads = $client->leads()
            ->when($employeeId, fn ($q) => $q->where('assigned_to', $employeeId))
            ->with(['assignedTo', 'stage', 'pipeline'])
            ->latest()
            ->get();
        $deals = $client->deals()
            ->when($employeeId, fn ($q) => $q->where('assigned_to', $employeeId))
            ->with(['assignedTo', 'stage', 'pipeline', 'lead'])
            ->withSum('payments', 'amount')
            ->latest()
            ->get();
        $oneTime = $deals->where('business_type', '!=', 'RECURRING')->where('status', 'won')->sum(fn ($d) => (float) $d->value);
        $recurring = $deals->where('business_type', 'RECURRING')->sum(fn ($d) => (float) ($d->payments_sum_amount ?? 0));

        return response()->json(['data' => [
            'client' => new ClientResource($client),
            'leads' => LeadResource::collection($leads)->resolve(),
            'deals' => DealResource::collection($deals)->resolve(),
            'revenue' => ['one_time_revenue' => $oneTime, 'recurring_revenue' => $recurring, 'total_revenue' => $oneTime + $recurring],
        ]]);
    }

    private function visibleQuery(Request $request): Builder
    {
        $user = $request->user();
        return Client::query()->where('organization_id', $user->organization_id)
            ->when($user->isEmployee(), fn ($q) => $q->where(fn ($visible) => $visible
                ->where('assigned_to', $user->id)
                ->orWhereHas('leads', fn ($lead) => $lead->where('assigned_to', $user->id))
                ->orWhereHas('deals', fn ($deal) => $deal->where('assigned_to', $user->id))));
    }

    private function authorizeVisible(Request $request, Client $client): void
    {
        if (! $this->visibleQuery($request)->whereKey($client->id)->exists()) abort(404);
    }
}
