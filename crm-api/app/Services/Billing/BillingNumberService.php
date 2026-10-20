<?php

namespace App\Services\Billing;

use App\Models\BillingDocument;
use App\Models\BillingDocumentSequence;
use App\Models\BillingProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingNumberService
{
    public function __construct(private readonly BillingAuditService $audit) {}

    public function ensureDefaults(int $organizationId, ?int $userId = null): void
    {
        $prefixes = ['proforma' => 'PI-', 'invoice' => 'INV-', 'payment_receipt' => 'RCP-', 'consolidated_receipt' => 'CR-'];
        foreach ($prefixes as $type => $prefix) {
            BillingDocumentSequence::firstOrCreate(
                ['organization_id' => $organizationId, 'document_type' => $type],
                ['prefix' => '{FY}/'.$prefix, 'suffix' => '', 'starting_number' => 1, 'next_number' => 1, 'padding' => 4, 'numbering_period' => 'financial_year', 'reset_on_period_change' => true, 'updated_by' => $userId],
            );
        }
    }

    public function preview(BillingDocumentSequence $sequence, CarbonInterface $date, ?BillingProfile $profile = null): string
    {
        $periodKey = $this->periodKey($sequence, $date, $profile?->financial_year_start_month ?? 4);
        $number = $sequence->next_number;
        if ($sequence->reset_on_period_change && $sequence->last_period_key !== null && $sequence->last_period_key !== $periodKey) {
            $number = $sequence->starting_number;
        }

        return $this->format($sequence, $number, $date, $profile?->financial_year_start_month ?? 4);
    }

    public function issue(BillingDocument $document, User $actor, CarbonInterface $date): BillingDocument
    {
        return DB::transaction(function () use ($document, $actor, $date): BillingDocument {
            $document = BillingDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->organization_id !== $actor->organization_id) {
                throw ValidationException::withMessages(['document' => 'The document does not belong to your organization.']);
            }
            if ($document->issued_at !== null || $document->document_number !== null) {
                throw ValidationException::withMessages(['document' => 'This document has already been issued.']);
            }

            $type = $document->document_type === 'proforma' ? 'proforma' : 'invoice';
            $profile = BillingProfile::where('organization_id', $document->organization_id)->first();
            $this->ensureDefaults($document->organization_id, $actor->id);
            $sequence = BillingDocumentSequence::where('organization_id', $document->organization_id)
                ->where('document_type', $type)->lockForUpdate()->firstOrFail();
            $periodKey = $this->periodKey($sequence, $date, $profile?->financial_year_start_month ?? 4);
            if ($sequence->reset_on_period_change && $sequence->last_period_key !== null && $sequence->last_period_key !== $periodKey) {
                $sequence->next_number = $sequence->starting_number;
            }
            $number = $this->format($sequence, $sequence->next_number, $date, $profile?->financial_year_start_month ?? 4);
            $sequence->forceFill(['next_number' => $sequence->next_number + 1, 'last_period_key' => $periodKey, 'updated_by' => $actor->id])->save();
            $issuerSnapshot = $document->issuer_snapshot;
            if (empty($issuerSnapshot) && $profile) {
                $issuerSnapshot = [
                    'legal_name' => $profile->legal_name, 'trade_name' => $profile->trade_name,
                    'registered_address' => $profile->registered_address, 'billing_address' => $profile->billing_address,
                    'city' => $profile->city, 'state' => $profile->state, 'postal_code' => $profile->postal_code,
                    'country' => $profile->country, 'contact_email' => $profile->contact_email,
                    'contact_phone' => $profile->contact_phone, 'website' => $profile->website,
                    'gstin' => $profile->gstin, 'pan' => $profile->pan, 'logo_path' => $profile->logo_path,
                    'signature_path' => $profile->signature_path, 'default_currency' => $profile->default_currency,
                    'document_footer' => $profile->document_footer, 'default_payment_link' => $profile->default_payment_link,
                    'bank_account' => $profile->bankAccounts()->where('is_active', true)->orderByDesc('is_default')->first()?->toArray(),
                ];
            }
            $document->forceFill(['document_number' => $number, 'status' => 'issued', 'issue_date' => $date->toDateString(), 'issued_at' => now(), 'issued_by' => $actor->id, 'issuer_snapshot' => $issuerSnapshot])->save();
            $this->audit->record($document->organization_id, $actor, 'document.issued', $document, null, ['document_number' => $number]);

            return $document->fresh();
        }, 3);
    }

    public function nextNumber(int $organizationId, string $type, User $actor, CarbonInterface $date): string
    {
        if (! in_array($type, ['payment_receipt', 'consolidated_receipt'], true)) {
            throw ValidationException::withMessages(['document_type' => 'Unsupported receipt sequence.']);
        }

        $profile = BillingProfile::where('organization_id', $organizationId)->first();
        $this->ensureDefaults($organizationId, $actor->id);
        $sequence = BillingDocumentSequence::where('organization_id', $organizationId)
            ->where('document_type', $type)->lockForUpdate()->firstOrFail();
        $periodKey = $this->periodKey($sequence, $date, $profile?->financial_year_start_month ?? 4);
        if ($sequence->reset_on_period_change && $sequence->last_period_key !== null && $sequence->last_period_key !== $periodKey) {
            $sequence->next_number = $sequence->starting_number;
        }
        $number = $this->format($sequence, $sequence->next_number, $date, $profile?->financial_year_start_month ?? 4);
        $sequence->forceFill(['next_number' => $sequence->next_number + 1, 'last_period_key' => $periodKey, 'updated_by' => $actor->id])->save();

        return $number;
    }

    private function periodKey(BillingDocumentSequence $sequence, CarbonInterface $date, int $fyStart): string
    {
        return match ($sequence->numbering_period) {
            'continuous' => 'continuous',
            'calendar_year' => $date->format('Y'),
            default => $this->financialYear($date, $fyStart),
        };
    }

    private function format(BillingDocumentSequence $sequence, int $number, CarbonInterface $date, int $fyStart): string
    {
        $tokens = ['{YYYY}' => $date->format('Y'), '{YY}' => $date->format('y'), '{FY}' => $this->financialYear($date, $fyStart)];

        return strtr($sequence->prefix, $tokens).str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT).strtr($sequence->suffix, $tokens);
    }

    private function financialYear(CarbonInterface $date, int $startMonth): string
    {
        $start = $date->month >= $startMonth ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }
}
