<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingCatalogueItem;
use App\Models\BillingProfile;
use App\Services\Billing\BillingAuditService;
use App\Services\Billing\BillingPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingCatalogueController extends Controller
{
    public function __construct(private readonly BillingPermissionService $permissions, private readonly BillingAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->require($request, 'view');
        $query = BillingCatalogueItem::where('organization_id', $request->user()->organization_id);
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"));
        }

        return response()->json($query->orderBy('name')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(Request $request): JsonResponse
    {
        $this->require($request, 'manage_settings');
        $data = $this->validated($request);
        $item = BillingCatalogueItem::create($data + ['organization_id' => $request->user()->organization_id, 'created_by' => $request->user()->id]);
        $this->audit->record($item->organization_id, $request->user(), 'catalogue.created', $item, null, $item->toArray(), $request);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, BillingCatalogueItem $catalogueItem): JsonResponse
    {
        $this->requireItem($request, $catalogueItem);
        $before = $catalogueItem->toArray();
        $catalogueItem->update($this->validated($request, $catalogueItem->id) + ['updated_by' => $request->user()->id]);
        $this->audit->record($catalogueItem->organization_id, $request->user(), 'catalogue.updated', $catalogueItem, $before, $catalogueItem->fresh()->toArray(), $request);

        return response()->json(['data' => $catalogueItem->fresh()]);
    }

    public function destroy(Request $request, BillingCatalogueItem $catalogueItem): JsonResponse
    {
        $this->requireItem($request, $catalogueItem);
        $catalogueItem->update(['is_active' => false, 'updated_by' => $request->user()->id]);
        $catalogueItem->delete();
        $this->audit->record($catalogueItem->organization_id, $request->user(), 'catalogue.archived', $catalogueItem, null, null, $request);

        return response()->json(['message' => 'Catalogue item archived.']);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $org = $request->user()->organization_id;

        return $request->validate([
            'type' => ['required', Rule::in(['product', 'service'])],
            'code' => ['required', 'string', 'max:64', Rule::unique('billing_catalogue_items', 'code')->where('organization_id', $org)->ignore($ignore)],
            'name' => ['required', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'], 'unit' => ['nullable', 'string', 'max:32'],
            'hsn_sac' => ['nullable', 'string', 'max:32'], 'default_tax_rate' => ['required', 'numeric', 'between:0,100'],
            'prices' => ['required', 'array', 'min:1'], 'prices.*' => ['numeric', 'min:0'],
            'is_recurring' => ['boolean'], 'is_active' => ['boolean'],
        ]);
    }

    private function require(Request $request, string $action): void
    {
        $profile = BillingProfile::where('organization_id', $request->user()->organization_id)->first();
        abort_unless($this->permissions->allows($request->user(), $profile, $action), 403);
    }

    private function requireItem(Request $request, BillingCatalogueItem $item): void
    {
        abort_unless($item->organization_id === $request->user()->organization_id, 404);
        $this->require($request, 'manage_settings');
    }
}
