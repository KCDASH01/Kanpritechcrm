<?php

namespace App\Services\Billing;

use App\Models\BillingConsolidatedReceipt;
use App\Models\BillingDocument;
use App\Models\BillingProfile;
use App\Models\BillingReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class BillingPdfService
{
    public const TEMPLATES = ['classic', 'modern', 'minimal', 'corporate', 'executive'];

    public function renderDocument(BillingDocument $document): string
    {
        $document->loadMissing('items');

        return $this->render($this->documentPayload($document));
    }

    public function storedDocument(BillingDocument $document): string
    {
        if ($document->issued_at === null) {
            throw new RuntimeException('Only issued documents can be stored as immutable PDFs.');
        }

        if ($bytes = $this->verifiedStoredPdf($document->pdf_path, $document->pdf_sha256)) {
            return $bytes;
        }

        $bytes = $this->renderDocument($document);
        $path = "billing/{$document->organization_id}/documents/{$document->uuid}.pdf";
        $this->store($path, $bytes);
        $hash = hash('sha256', $bytes);

        DB::transaction(function () use ($document, $path, $hash): void {
            $locked = BillingDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->pdf_path === null) {
                $locked->forceFill(['pdf_path' => $path, 'pdf_sha256' => $hash])->save();
            }
        }, 3);

        return $bytes;
    }

    public function storedReceipt(BillingReceipt $receipt): string
    {
        if ($bytes = $this->verifiedStoredPdf($receipt->pdf_path, $receipt->pdf_sha256)) {
            return $bytes;
        }

        $bytes = $this->render($this->receiptPayload($receipt));
        $path = "billing/{$receipt->organization_id}/receipts/{$receipt->uuid}.pdf";
        $this->store($path, $bytes);
        $hash = hash('sha256', $bytes);
        BillingReceipt::whereKey($receipt->id)->whereNull('pdf_path')->update(['pdf_path' => $path, 'pdf_sha256' => $hash]);

        return $bytes;
    }

    public function storedConsolidatedReceipt(BillingConsolidatedReceipt $receipt): string
    {
        if ($bytes = $this->verifiedStoredPdf($receipt->pdf_path, $receipt->pdf_sha256)) {
            return $bytes;
        }

        $receipt->loadMissing('payments.payment.deal');
        $bytes = $this->render($this->consolidatedPayload($receipt));
        $path = "billing/{$receipt->organization_id}/consolidated-receipts/{$receipt->uuid}.pdf";
        $this->store($path, $bytes);
        $hash = hash('sha256', $bytes);
        BillingConsolidatedReceipt::whereKey($receipt->id)->whereNull('pdf_path')->update(['pdf_path' => $path, 'pdf_sha256' => $hash]);

        return $bytes;
    }

    public function templatePreview(BillingProfile $profile, string $template): string
    {
        if (! in_array($template, self::TEMPLATES, true)) {
            throw new RuntimeException('Unknown billing document template.');
        }

        $issuer = $this->profileIssuer($profile);
        $issuer['logo_data_uri'] = $this->assetDataUri($profile->logo_path);
        $issuer['signature_data_uri'] = $this->assetDataUri($profile->signature_path);
        $bank = $profile->bankAccounts()->where('is_active', true)->orderByDesc('is_default')->first()?->toArray();

        return $this->render([
            'kind' => 'document', 'template' => $template, 'document_type' => 'invoice',
            'document_number' => 'SAMPLE-0001', 'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays($profile->payment_terms_days)->toDateString(),
            'currency' => $profile->default_currency, 'issuer' => $issuer, 'bank' => $bank,
            'customer' => ['company_name' => 'Sample Customer Pvt Ltd', 'contact_name' => 'Aarav Sharma', 'address' => '21 Market Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'postal_code' => '560001', 'country' => 'India', 'gstin' => '29ABCDE1234F1Z5', 'phone' => '+91 90000 00000'],
            'items' => [
                ['product_name' => 'Professional Services', 'details' => 'Sample line item demonstrating the selected Synq template.', 'hsn_sac' => '998314', 'quantity' => 1, 'unit_price' => 50000, 'taxable_amount' => 50000, 'gst_rate' => 18, 'cgst_amount' => 4500, 'sgst_amount' => 4500, 'igst_amount' => 0, 'line_total' => 59000],
            ],
            'subtotal' => 50000, 'taxable_total' => 50000, 'cgst_total' => 4500,
            'sgst_total' => 4500, 'igst_total' => 0, 'grand_total' => 59000,
            'notes' => $profile->default_notes, 'terms' => 'Payment is due according to the agreed terms.',
            'footer' => $profile->document_footer,
        ]);
    }

    public function documentPayload(BillingDocument $document): array
    {
        $issued = $document->issued_at !== null;
        $profile = $issued ? null : BillingProfile::where('organization_id', $document->organization_id)->first();
        $issuer = $issued ? ($document->issuer_snapshot ?: []) : ($profile ? $this->profileIssuer($profile) : []);
        $issuer['logo_data_uri'] = $this->assetDataUri($issuer['logo_path'] ?? $profile?->logo_path);
        $issuer['signature_data_uri'] = $this->assetDataUri($issuer['signature_path'] ?? $profile?->signature_path);
        $bank = $issuer['bank_account'] ?? ($profile?->bankAccounts()->where('is_active', true)->orderByDesc('is_default')->first()?->toArray());

        return [
            'kind' => 'document', 'template' => $document->template, 'document_type' => $document->document_type,
            'document_number' => $document->document_number ?: 'DRAFT', 'issue_date' => $document->issue_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(), 'currency' => $document->currency,
            'issuer' => $issuer, 'customer' => $document->customer_snapshot ?: [], 'bank' => $bank,
            'items' => $document->items->map(fn ($item) => [
                'product_name' => $item->metadata['product_name'] ?? $item->description,
                'details' => $item->metadata['details'] ?? null, 'hsn_sac' => $item->hsn_sac,
                'quantity' => $item->quantity, 'unit' => $item->unit, 'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type, 'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount, 'taxable_amount' => $item->taxable_amount,
                'gst_rate' => $item->gst_rate, 'cgst_amount' => $item->cgst_amount,
                'sgst_amount' => $item->sgst_amount, 'igst_amount' => $item->igst_amount,
                'line_total' => $item->line_total,
            ])->all(),
            'subtotal' => $document->subtotal, 'discount_total' => $document->discount_total,
            'taxable_total' => $document->taxable_total, 'cgst_total' => $document->cgst_total,
            'sgst_total' => $document->sgst_total, 'igst_total' => $document->igst_total,
            'rounding_adjustment' => $document->rounding_adjustment, 'grand_total' => $document->grand_total,
            'notes' => $document->notes, 'terms' => $document->terms, 'footer' => $document->footer,
            'payment_link' => ($document->tax_context ?? [])['payment_link'] ?? null,
        ];
    }

    private function receiptPayload(BillingReceipt $receipt): array
    {
        $issuer = $receipt->issuer_snapshot ?: [];
        $issuer['logo_data_uri'] = $this->assetDataUri($issuer['logo_path'] ?? null);
        $issuer['signature_data_uri'] = $this->assetDataUri($issuer['signature_path'] ?? null);
        $payment = $receipt->payment_snapshot ?: [];

        return [
            'kind' => 'receipt', 'receipt_number' => $receipt->receipt_number,
            'receipt_date' => $receipt->issued_at?->toDateString(), 'currency' => $payment['currency'] ?? 'INR',
            'issuer' => $issuer, 'customer' => $receipt->customer_snapshot ?: [],
            'payment' => [
                'payment_date' => $payment['payment_date'] ?? null, 'payment_method' => $payment['payment_mode'] ?? null,
                'reference' => $payment['txn_or_utr_number'] ?? null, 'product_details' => $payment['product_details'] ?? null,
                'amount' => $payment['amount'] ?? 0,
            ],
            'footer' => $issuer['document_footer'] ?? null,
        ];
    }

    private function consolidatedPayload(BillingConsolidatedReceipt $receipt): array
    {
        $issuer = $receipt->issuer_snapshot ?: [];
        $issuer['logo_data_uri'] = $this->assetDataUri($issuer['logo_path'] ?? null);
        $issuer['signature_data_uri'] = $this->assetDataUri($issuer['signature_path'] ?? null);
        $currency = $issuer['default_currency'] ?? 'INR';

        return [
            'kind' => 'consolidated_receipt', 'receipt_number' => $receipt->receipt_number,
            'receipt_date' => $receipt->issued_at?->toDateString(), 'period_start' => $receipt->period_start?->toDateString(),
            'period_end' => $receipt->period_end?->toDateString(), 'currency' => $currency,
            'issuer' => $issuer, 'customer' => $receipt->customer_snapshot ?: [],
            'represented_total' => $receipt->represented_total,
            'payments' => $receipt->payments->map(function ($entry) {
                $payment = $entry->payment;

                return [
                    'receipt_number' => $payment?->id ? 'PAY-'.$payment->id : null,
                    'payment_date' => $payment?->payment_date?->toDateString(), 'payment_method' => $payment?->payment_mode,
                    'reference' => $payment?->txn_or_utr_number, 'product_details' => $payment?->deal?->title,
                    'amount' => $entry->amount_snapshot,
                ];
            })->all(),
            'footer' => $issuer['document_footer'] ?? null,
        ];
    }

    private function profileIssuer(BillingProfile $profile): array
    {
        return [
            'legal_name' => $profile->legal_name, 'trade_name' => $profile->trade_name,
            'registered_address' => $profile->registered_address, 'billing_address' => $profile->billing_address,
            'city' => $profile->city, 'state' => $profile->state, 'postal_code' => $profile->postal_code,
            'country' => $profile->country, 'contact_email' => $profile->contact_email,
            'contact_phone' => $profile->contact_phone, 'website' => $profile->website,
            'gstin' => $profile->gstin, 'pan' => $profile->pan, 'logo_path' => $profile->logo_path,
            'signature_path' => $profile->signature_path, 'default_currency' => $profile->default_currency,
            'document_footer' => $profile->document_footer,
        ];
    }

    private function render(array $payload): string
    {
        $script = (string) config('billing.pdf.renderer');
        if (! is_file($script)) {
            throw new RuntimeException('Billing PDF renderer is not built. Run npm ci and npm run build in billing-pdf-renderer.');
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $process = new Process([(string) config('billing.pdf.node_binary', 'node'), $script]);
        $process->setInput($json);
        $process->setTimeout((float) config('billing.pdf.timeout_seconds', 45));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Billing PDF renderer failed: '.trim($process->getErrorOutput()));
        }
        $bytes = $process->getOutput();
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('Billing PDF renderer returned an invalid document.');
        }

        return $bytes;
    }

    private function assetDataUri(?string $path): ?string
    {
        if (! $path || str_contains($path, '..') || preg_match('/^https?:/i', $path)) {
            return null;
        }
        $disk = Storage::disk((string) config('billing.pdf.disk', 'local'));
        if (! $disk->exists($path)) {
            return null;
        }
        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    private function verifiedStoredPdf(?string $path, ?string $expectedHash): ?string
    {
        if (! $path || ! $expectedHash) {
            return null;
        }
        $disk = Storage::disk((string) config('billing.pdf.disk', 'local'));
        if (! $disk->exists($path)) {
            return null;
        }
        $bytes = $disk->get($path);
        if (! hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('Stored billing PDF failed its integrity check.');
        }

        return $bytes;
    }

    private function store(string $path, string $bytes): void
    {
        if (! Storage::disk((string) config('billing.pdf.disk', 'local'))->put($path, $bytes)) {
            throw new RuntimeException('The billing PDF could not be stored.');
        }
    }
}
