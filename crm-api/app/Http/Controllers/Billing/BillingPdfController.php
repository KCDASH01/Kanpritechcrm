<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingConsolidatedReceipt;
use App\Models\BillingDocument;
use App\Models\BillingProfile;
use App\Models\BillingReceipt;
use App\Services\Billing\BillingPdfService;
use App\Services\Billing\BillingPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class BillingPdfController extends Controller
{
    public function __construct(
        private readonly BillingPdfService $pdf,
        private readonly BillingPermissionService $permissions,
    ) {}

    public function template(Request $request, string $template): Response
    {
        abort_unless(in_array($template, BillingPdfService::TEMPLATES, true), 404);
        $profile = $this->profile($request);
        $this->authorizePermission($request, $profile, 'manage_settings');

        return $this->pdfResponse(
            $this->pdf->templatePreview($profile, $template),
            "growneq-{$template}-template-preview.pdf",
            false,
        );
    }

    public function document(Request $request, BillingDocument $billingDocument): Response
    {
        abort_unless($billingDocument->organization_id === $request->user()->organization_id, 404);
        Gate::forUser($request->user())->authorize('view', $billingDocument);
        $bytes = $billingDocument->issued_at
            ? $this->pdf->storedDocument($billingDocument)
            : $this->pdf->renderDocument($billingDocument);

        return $this->pdfResponse(
            $bytes,
            ($billingDocument->document_number ?: 'draft-'.$billingDocument->uuid).'.pdf',
            $request->boolean('download'),
        );
    }

    public function receipt(Request $request, BillingReceipt $billingReceipt): Response
    {
        $profile = $this->profile($request);
        $this->authorizePermission($request, $profile, 'view');
        abort_unless($billingReceipt->organization_id === $request->user()->organization_id, 404);
        if ($request->user()->isEmployee()) {
            $billingReceipt->loadMissing('payment.deal');
            abort_unless(in_array($billingReceipt->payment?->deal?->assigned_to, [$request->user()->id, null], true), 403);
        }

        return $this->pdfResponse(
            $this->pdf->storedReceipt($billingReceipt),
            $billingReceipt->receipt_number.'.pdf',
            $request->boolean('download'),
        );
    }

    public function consolidatedReceipt(Request $request, BillingConsolidatedReceipt $billingConsolidatedReceipt): Response
    {
        $profile = $this->profile($request);
        $this->authorizePermission($request, $profile, 'view');
        abort_unless($billingConsolidatedReceipt->organization_id === $request->user()->organization_id, 404);
        if ($request->user()->isEmployee()) {
            $billingConsolidatedReceipt->loadMissing('payments.payment.deal');
            $authorized = $billingConsolidatedReceipt->payments->every(
                fn ($entry) => in_array($entry->payment?->deal?->assigned_to, [$request->user()->id, null], true),
            );
            abort_unless($authorized, 403);
        }

        return $this->pdfResponse(
            $this->pdf->storedConsolidatedReceipt($billingConsolidatedReceipt),
            $billingConsolidatedReceipt->receipt_number.'.pdf',
            $request->boolean('download'),
        );
    }

    private function profile(Request $request): BillingProfile
    {
        return BillingProfile::where('organization_id', $request->user()->organization_id)->firstOrFail();
    }

    private function authorizePermission(Request $request, BillingProfile $profile, string $permission): void
    {
        abort_unless($this->permissions->allows($request->user(), $profile, $permission), 403, 'You do not have permission to access this billing document.');
    }

    private function pdfResponse(string $bytes, string $filename, bool $download): Response
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'billing-document.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
