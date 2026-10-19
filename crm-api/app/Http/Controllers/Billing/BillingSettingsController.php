<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CalculateBillingDocumentRequest;
use App\Http\Requests\Billing\StoreBillingBankAccountRequest;
use App\Http\Requests\Billing\UpdateBillingProfileRequest;
use App\Http\Requests\Billing\UpdateBillingSequenceRequest;
use App\Models\BillingAuditLog;
use App\Models\BillingBankAccount;
use App\Models\BillingDocument;
use App\Models\BillingDocumentSequence;
use App\Models\BillingProfile;
use App\Models\User;
use App\Services\Billing\BillingAuditService;
use App\Services\Billing\BillingCalculationService;
use App\Services\Billing\BillingNumberService;
use App\Services\Billing\BillingPermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingSettingsController extends Controller
{
    public function __construct(
        private readonly BillingPermissionService $permissions,
        private readonly BillingNumberService $numbers,
        private readonly BillingAuditService $audit,
        private readonly BillingCalculationService $calculator,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'view');
        $this->numbers->ensureDefaults($request->user()->organization_id, $request->user()->id);

        return response()->json(['data' => $this->settingsPayload($profile->fresh())]);
    }

    public function update(UpdateBillingProfileRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'manage_settings');
        $before = $profile->toArray();
        $profile->fill($request->validated());
        $profile->updated_by = $request->user()->id;
        $profile->save();
        $this->audit->record($profile->organization_id, $request->user(), 'settings.updated', $profile, $before, $profile->fresh()->toArray(), $request);

        return response()->json(['data' => $this->settingsPayload($profile->fresh())]);
    }

    public function storeBank(StoreBillingBankAccountRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'manage_settings');
        $bank = DB::transaction(function () use ($request, $profile) {
            if ($request->boolean('is_default')) {
                BillingBankAccount::where('organization_id', $profile->organization_id)->update(['is_default' => false]);
            }

            return BillingBankAccount::create($request->validated() + ['organization_id' => $profile->organization_id, 'billing_profile_id' => $profile->id]);
        });
        $this->audit->record($profile->organization_id, $request->user(), 'bank.created', $bank, null, $bank->toArray(), $request);

        return response()->json(['data' => $bank], 201);
    }

    public function updateBank(StoreBillingBankAccountRequest $request, BillingBankAccount $bank): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeBank($request->user(), $profile, $bank);
        $before = $bank->toArray();
        DB::transaction(function () use ($request, $bank, $profile): void {
            if ($request->boolean('is_default')) {
                BillingBankAccount::where('organization_id', $profile->organization_id)->whereKeyNot($bank->id)->update(['is_default' => false]);
            }
            $bank->update($request->validated());
        });
        $this->audit->record($profile->organization_id, $request->user(), 'bank.updated', $bank, $before, $bank->fresh()->toArray(), $request);

        return response()->json(['data' => $bank->fresh()]);
    }

    public function destroyBank(Request $request, BillingBankAccount $bank): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeBank($request->user(), $profile, $bank);
        $before = $bank->toArray();
        $this->audit->record($profile->organization_id, $request->user(), 'bank.deleted', $bank, $before, null, $request);
        $bank->delete();

        return response()->json(status: 204);
    }

    public function templates(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'view');

        return response()->json(['data' => [
            ['id' => 'classic', 'name' => 'Classic', 'available' => true],
            ['id' => 'modern', 'name' => 'Modern', 'available' => true],
            ['id' => 'minimal', 'name' => 'Minimal', 'available' => true],
            ['id' => 'corporate', 'name' => 'Corporate', 'available' => true],
            ['id' => 'executive', 'name' => 'Executive', 'available' => true],
        ], 'meta' => ['pdf_rendering_available' => is_file((string) config('billing.pdf.renderer')), 'phase' => 2]]);
    }

    public function uploadAsset(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['logo', 'signature'], true), 404);
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'manage_settings');
        $request->validate(['asset' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048']]);
        $extension = strtolower($request->file('asset')->getClientOriginalExtension());
        $path = "billing/{$profile->organization_id}/branding/{$type}-".Str::uuid().'.'.$extension;
        Storage::disk((string) config('billing.pdf.disk', 'local'))->putFileAs(
            dirname($path),
            $request->file('asset'),
            basename($path),
        );
        $column = $type === 'logo' ? 'logo_path' : 'signature_path';
        $before = [$column => $profile->{$column}];
        $profile->update([$column => $path, 'updated_by' => $request->user()->id]);
        $this->audit->record($profile->organization_id, $request->user(), 'branding.'.$type.'.updated', $profile, $before, [$column => $path], $request);

        return response()->json(['data' => $this->settingsPayload($profile->fresh())]);
    }

    public function sequences(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'view');
        $this->numbers->ensureDefaults($profile->organization_id, $request->user()->id);
        $date = now($request->user()->organization->timezone ?: config('app.timezone'));
        $rows = BillingDocumentSequence::where('organization_id', $profile->organization_id)->orderBy('id')->get()
            ->map(fn ($sequence) => $sequence->toArray() + ['preview' => $this->numbers->preview($sequence, $date, $profile)]);

        return response()->json(['data' => $rows]);
    }

    public function updateSequence(UpdateBillingSequenceRequest $request, string $documentType): JsonResponse
    {
        if (! in_array($documentType, BillingDocumentSequence::TYPES, true)) {
            abort(404);
        }
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'manage_sequences');
        $this->numbers->ensureDefaults($profile->organization_id, $request->user()->id);

        $sequence = BillingDocumentSequence::where('organization_id', $profile->organization_id)->where('document_type', $documentType)->firstOrFail();
        $data = $request->validated();
        if ((int) $data['next_number'] < $sequence->next_number && BillingDocument::where('organization_id', $profile->organization_id)->where('document_type', $documentType === 'proforma' ? 'proforma' : 'invoice')->whereNotNull('document_number')->exists()) {
            throw ValidationException::withMessages(['next_number' => 'Next number cannot move backwards after documents have been issued.']);
        }
        $before = $sequence->toArray();
        $sequence->fill($data + ['updated_by' => $request->user()->id])->save();
        $this->audit->record($profile->organization_id, $request->user(), 'sequence.updated', $sequence, $before, $sequence->fresh()->toArray(), $request);
        $date = now($request->user()->organization->timezone ?: config('app.timezone'));

        return response()->json(['data' => $sequence->fresh()->toArray() + ['preview' => $this->numbers->preview($sequence->fresh(), $date, $profile)]]);
    }

    public function previewSequence(Request $request, string $documentType): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'view');
        $request->validate(['date' => ['nullable', 'date']]);
        $this->numbers->ensureDefaults($profile->organization_id, $request->user()->id);
        $sequence = BillingDocumentSequence::where('organization_id', $profile->organization_id)->where('document_type', $documentType)->firstOrFail();
        $date = $request->filled('date')
            ? Carbon::parse((string) $request->input('date'), $request->user()->organization->timezone)
            : now($request->user()->organization->timezone);

        return response()->json(['data' => ['preview' => $this->numbers->preview($sequence, $date, $profile), 'date' => $date->toDateString()]]);
    }

    public function getPermissions(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());

        return response()->json(['data' => ['matrix' => $this->permissions->matrix($profile), 'effective' => $this->permissions->effective($request->user(), $profile)]]);
    }

    public function updatePermissions(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'Only the organization owner can change billing permissions.');
        $matrix = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.admin' => ['required', 'array'], 'permissions.employee' => ['required', 'array'],
            'permissions.admin.*' => ['boolean'], 'permissions.employee.*' => ['boolean'],
        ])['permissions'];
        foreach (['admin', 'employee'] as $role) {
            $matrix[$role] = array_intersect_key($matrix[$role], array_flip(BillingPermissionService::ACTIONS));
        }
        $profile = $this->profile($request->user());
        $before = $profile->permissions;
        $profile->update(['permissions' => $matrix, 'updated_by' => $request->user()->id]);
        $this->audit->record($profile->organization_id, $request->user(), 'permissions.updated', $profile, ['permissions' => $before], ['permissions' => $matrix], $request);

        return $this->getPermissions($request);
    }

    public function calculate(CalculateBillingDocumentRequest $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'create_draft');
        $data = $request->validated();

        return response()->json(['data' => $this->calculator->calculate($data['items'], $data['interstate'], $data['gst_enabled'], $data['rounding_scale'] ?? 2)]);
    }

    public function audits(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user());
        $this->authorizeAction($request->user(), $profile, 'view_audit');
        $rows = BillingAuditLog::where('organization_id', $profile->organization_id)->latest()->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return response()->json($rows);
    }

    private function profile(User $user): BillingProfile
    {
        return BillingProfile::firstOrCreate(['organization_id' => $user->organization_id], [
            'legal_name' => $user->organization->name, 'trade_name' => $user->organization->name,
            'registered_address' => $user->organization->address, 'billing_address' => $user->organization->address,
            'city' => $user->organization->city, 'country' => 'IN', 'contact_email' => $user->organization->email,
            'contact_phone' => $user->organization->phone, 'website' => $user->organization->website,
            'supported_currencies' => ['INR'], 'tax_settings' => ['gst_enabled' => true, 'default_gst_rate' => 18, 'rounding_scale' => 2],
            'permissions' => $this->permissions->defaults(), 'updated_by' => $user->id,
        ]);
    }

    private function settingsPayload(BillingProfile $profile): array
    {
        return array_merge($profile->toArray(), [
            'bank_accounts' => $profile->bankAccounts()->orderByDesc('is_default')->orderBy('id')->get(),
            'permissions' => $this->permissions->matrix($profile),
        ]);
    }

    private function authorizeAction(User $user, BillingProfile $profile, string $action): void
    {
        abort_unless($this->permissions->allows($user, $profile, $action), 403, 'You do not have permission to perform this billing action.');
    }

    private function authorizeBank(User $user, BillingProfile $profile, BillingBankAccount $bank): void
    {
        $this->authorizeAction($user, $profile, 'manage_settings');
        abort_unless($bank->organization_id === $user->organization_id, 404);
    }
}
