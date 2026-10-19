<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckSubscription;
use App\Models\BillingBankAccount;
use App\Models\BillingCatalogueItem;
use App\Models\BillingConsolidatedReceipt;
use App\Models\BillingConsolidatedReceiptPayment;
use App\Models\BillingDocument;
use App\Models\BillingDocumentItem;
use App\Models\BillingDocumentSequence;
use App\Models\BillingProfile;
use App\Models\BillingReceipt;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAuditService;
use App\Services\Billing\BillingNumberService;
use App\Services\Billing\BillingPermissionService;
use App\Services\Billing\PaymentAllocationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class BillingFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(CheckSubscription::class);
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('website')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('country')->nullable();
            $t->string('timezone')->nullable();
            $t->json('settings')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('employee');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('phone_normalized')->nullable();
            $t->string('job_title')->nullable();
            $t->string('website')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->string('first_name')->default('Lead');
            $t->string('last_name')->nullable();
            $t->string('company')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('country')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('deals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->string('title');
            $t->decimal('value', 15, 2)->default(0);
            $t->string('currency', 3)->default('INR');
            $t->string('status')->default('open');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('recurring_businesses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->timestamps();
        });
        Schema::create('deal_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('deal_id');
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('created_by');
            $t->decimal('amount', 15, 2);
            $t->date('payment_date');
            $t->string('payment_mode')->default('other');
            $t->string('txn_or_utr_number')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_10_19_000001_create_billing_foundation.php'))->up();
        (require database_path('migrations/2026_10_20_000001_add_phase3_billing_workflow.php'))->up();
    }

    public function test_settings_are_isolated_and_employee_cannot_modify_them(): void
    {
        [$ownerA, $employeeA, $ownerB] = $this->users();
        Sanctum::actingAs($ownerA);
        $this->getJson('/api/billing-invoicing/settings')->assertOk()->assertJsonPath('data.legal_name', 'Organization A');
        $this->putJson('/api/billing-invoicing/settings', $this->profilePayload('A Legal'))->assertOk()->assertJsonPath('data.legal_name', 'A Legal');

        Sanctum::actingAs($ownerB);
        $this->getJson('/api/billing-invoicing/settings')->assertOk()->assertJsonPath('data.legal_name', 'Organization B');
        self::assertSame('A Legal', BillingProfile::where('organization_id', $ownerA->organization_id)->value('legal_name'));

        Sanctum::actingAs($employeeA);
        $this->putJson('/api/billing-invoicing/settings', $this->profilePayload('Forbidden'))->assertForbidden();
    }

    public function test_cross_organization_bank_account_access_is_hidden(): void
    {
        [$ownerA, , $ownerB] = $this->users();
        Sanctum::actingAs($ownerA);
        $profile = $this->getJson('/api/billing-invoicing/settings')->json('data');
        $bank = BillingBankAccount::create(['organization_id' => $ownerA->organization_id, 'billing_profile_id' => $profile['id'], 'account_name' => 'Primary', 'bank_name' => 'Bank', 'account_number' => '1234', 'currency' => 'INR']);

        Sanctum::actingAs($ownerB);
        $this->deleteJson("/api/billing-invoicing/bank-accounts/{$bank->id}")->assertNotFound();
        self::assertDatabaseHas('billing_bank_accounts', ['id' => $bank->id]);
    }

    public function test_catalogue_is_organization_scoped_and_snapshotted_on_a_lead_proforma(): void
    {
        [$ownerA, , $ownerB] = $this->users();
        Sanctum::actingAs($ownerA);
        $this->getJson('/api/billing-invoicing/settings')->assertOk();
        $item = $this->postJson('/api/billing-invoicing/catalogue', [
            'type' => 'service', 'code' => 'CRM-SETUP', 'name' => 'CRM Setup', 'description' => 'Implementation',
            'unit' => 'service', 'hsn_sac' => '998314', 'default_tax_rate' => 18, 'prices' => ['INR' => 50000],
            'is_recurring' => false, 'is_active' => true,
        ])->assertCreated()->json('data');
        $leadId = DB::table('leads')->insertGetId(['organization_id' => $ownerA->organization_id, 'assigned_to' => $ownerA->id, 'first_name' => 'Aarav', 'company' => 'Customer A', 'created_at' => now(), 'updated_at' => now()]);
        $document = $this->postJson('/api/billing-invoicing/documents', [
            'document_type' => 'proforma', 'lead_id' => $leadId, 'currency' => 'INR', 'template' => 'classic',
            'items' => [['catalogue_item_id' => $item['id'], 'description' => 'Adjusted implementation', 'quantity' => 1, 'unit' => 'service', 'unit_price' => 55000, 'gst_rate' => 18]],
        ])->assertCreated()->assertJsonPath('data.client_id', null)->assertJsonPath('data.deal_id', null)->json('data');
        self::assertSame('CRM-SETUP', BillingDocumentItem::where('billing_document_id', $document['id'])->firstOrFail()->catalogue_snapshot['code']);

        Sanctum::actingAs($ownerB);
        $this->getJson('/api/billing-invoicing/catalogue')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/billing-invoicing/documents/'.$document['id'])->assertNotFound();
        self::assertSame(1, BillingCatalogueItem::count());
    }

    public function test_invoice_draft_requires_matching_client_and_deal_and_does_not_create_payment(): void
    {
        [$owner] = $this->users();
        Sanctum::actingAs($owner);
        $this->getJson('/api/billing-invoicing/settings')->assertOk();
        $client = Client::create(['organization_id' => $owner->organization_id, 'created_by' => $owner->id, 'first_name' => 'Client']);
        $deal = Deal::create(['organization_id' => $owner->organization_id, 'client_id' => $client->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'title' => 'Deal', 'value' => 1000]);
        $this->postJson('/api/billing-invoicing/documents', ['document_type' => 'invoice', 'currency' => 'INR', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000]]])->assertUnprocessable();
        $this->postJson('/api/billing-invoicing/documents', ['document_type' => 'invoice', 'client_id' => $client->id, 'deal_id' => $deal->id, 'currency' => 'INR', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000]]])->assertCreated();
        self::assertSame(0, DealPayment::count());
    }

    public function test_issued_lead_proforma_can_be_linked_once_to_its_converted_client_and_deal(): void
    {
        [$owner] = $this->users();
        Sanctum::actingAs($owner);
        $this->getJson('/api/billing-invoicing/settings')->assertOk();
        $leadId = DB::table('leads')->insertGetId(['organization_id' => $owner->organization_id, 'assigned_to' => $owner->id, 'first_name' => 'Converted', 'created_at' => now(), 'updated_at' => now()]);
        $client = Client::create(['organization_id' => $owner->organization_id, 'created_by' => $owner->id, 'first_name' => 'Converted']);
        $deal = Deal::create(['organization_id' => $owner->organization_id, 'client_id' => $client->id, 'lead_id' => $leadId, 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'title' => 'Converted Deal', 'value' => 1000]);
        $document = BillingDocument::create(['organization_id' => $owner->organization_id, 'lead_id' => $leadId, 'document_type' => 'proforma', 'document_number' => 'PI-1', 'status' => 'issued', 'issued_at' => now(), 'currency' => 'INR', 'grand_total' => 1000, 'created_by' => $owner->id, 'issued_by' => $owner->id]);

        $this->postJson("/api/billing-invoicing/documents/{$document->id}/link-commercial", ['client_id' => $client->id, 'deal_id' => $deal->id])
            ->assertOk()->assertJsonPath('data.client_id', $client->id)->assertJsonPath('data.deal_id', $deal->id);

        $otherClient = Client::create(['organization_id' => $owner->organization_id, 'created_by' => $owner->id, 'first_name' => 'Other']);
        $otherDeal = Deal::create(['organization_id' => $owner->organization_id, 'client_id' => $otherClient->id, 'lead_id' => $leadId, 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'title' => 'Other Deal', 'value' => 1000]);
        $this->postJson("/api/billing-invoicing/documents/{$document->id}/link-commercial", ['client_id' => $otherClient->id, 'deal_id' => $otherDeal->id])->assertUnprocessable();
    }

    public function test_number_assignment_is_unique_resets_by_financial_year_and_is_immutable(): void
    {
        [$owner] = $this->users();
        $profile = BillingProfile::create(['organization_id' => $owner->organization_id, 'legal_name' => 'Org', 'financial_year_start_month' => 4]);
        $sequence = BillingDocumentSequence::create(['organization_id' => $owner->organization_id, 'document_type' => 'invoice', 'prefix' => '{FY}/INV-', 'starting_number' => 50, 'next_number' => 50, 'padding' => 4, 'numbering_period' => 'financial_year', 'reset_on_period_change' => true]);
        $service = new BillingNumberService(new BillingAuditService);
        $first = BillingDocument::create(['organization_id' => $owner->organization_id, 'document_type' => 'invoice', 'currency' => 'INR', 'grand_total' => 100, 'created_by' => $owner->id]);
        $second = BillingDocument::create(['organization_id' => $owner->organization_id, 'document_type' => 'invoice', 'currency' => 'INR', 'grand_total' => 200, 'created_by' => $owner->id]);

        self::assertSame('2026-27/INV-0050', $service->issue($first, $owner, Carbon::parse('2026-04-01'))->document_number);
        self::assertSame('2026-27/INV-0051', $service->issue($second, $owner, Carbon::parse('2026-04-02'))->document_number);
        self::assertSame(52, $sequence->fresh()->next_number);

        $newYear = BillingDocument::create(['organization_id' => $owner->organization_id, 'document_type' => 'invoice', 'currency' => 'INR', 'grand_total' => 300, 'created_by' => $owner->id]);
        self::assertSame('2027-28/INV-0050', $service->issue($newYear, $owner, Carbon::parse('2027-04-01'))->document_number);

        $this->expectException(LogicException::class);
        $first->fresh()->update(['grand_total' => 999]);
    }

    public function test_allocation_references_existing_payment_and_prevents_over_allocation(): void
    {
        [$owner] = $this->users();
        $client = Client::create(['organization_id' => $owner->organization_id, 'created_by' => $owner->id, 'first_name' => 'Client']);
        $deal = Deal::create(['organization_id' => $owner->organization_id, 'client_id' => $client->id, 'created_by' => $owner->id, 'title' => 'Deal', 'value' => 1000]);
        $payment = DealPayment::create(['organization_id' => $owner->organization_id, 'deal_id' => $deal->id, 'created_by' => $owner->id, 'amount' => 500, 'payment_date' => '2026-10-19']);
        $invoice = BillingDocument::create(['organization_id' => $owner->organization_id, 'client_id' => $client->id, 'deal_id' => $deal->id, 'document_type' => 'invoice', 'document_number' => 'INV-1', 'status' => 'issued', 'issued_at' => now(), 'currency' => 'INR', 'grand_total' => 1000, 'created_by' => $owner->id, 'issued_by' => $owner->id]);
        $service = new PaymentAllocationService;
        $allocation = $service->allocate($invoice, $payment, '400.00', $owner);
        self::assertSame('400.00', $allocation->amount);
        self::assertSame(1, DealPayment::count(), 'Allocating must not create another payment.');

        $this->expectException(ValidationException::class);
        $service->allocate($invoice, $payment, '101.00', $owner);
    }

    public function test_all_synq_templates_render_as_server_side_pdfs(): void
    {
        [$owner] = $this->users();
        Sanctum::actingAs($owner);
        $this->getJson('/api/billing-invoicing/settings')->assertOk();

        foreach (['classic', 'modern', 'minimal', 'corporate', 'executive'] as $template) {
            $response = $this->get("/api/billing-invoicing/templates/{$template}/preview");
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            self::assertStringStartsWith('%PDF-', $response->getContent(), "{$template} did not render a PDF.");
        }
    }

    public function test_issued_pdf_is_privately_stored_hashed_and_immutable(): void
    {
        Storage::fake('local');
        [$owner] = $this->users();
        $profile = BillingProfile::create([
            'organization_id' => $owner->organization_id, 'legal_name' => 'Organization A',
            'trade_name' => 'Growneq', 'default_currency' => 'INR', 'supported_currencies' => ['INR'],
            'permissions' => app(BillingPermissionService::class)->defaults(),
        ]);
        BillingDocumentSequence::create(['organization_id' => $owner->organization_id, 'document_type' => 'invoice', 'prefix' => 'INV-', 'next_number' => 1]);
        $document = BillingDocument::create([
            'organization_id' => $owner->organization_id, 'document_type' => 'invoice', 'currency' => 'INR',
            'customer_snapshot' => ['company_name' => 'Customer A', 'state' => 'Odisha'],
            'subtotal' => 1000, 'taxable_total' => 1000, 'cgst_total' => 90, 'sgst_total' => 90,
            'grand_total' => 1180, 'template' => 'executive', 'created_by' => $owner->id,
        ]);
        $item = BillingDocumentItem::create([
            'billing_document_id' => $document->id, 'line_number' => 1, 'description' => 'CRM Service',
            'quantity' => 1, 'unit_price' => 1000, 'taxable_amount' => 1000, 'gst_rate' => 18,
            'cgst_amount' => 90, 'sgst_amount' => 90, 'line_total' => 1180,
        ]);
        app(BillingNumberService::class)->issue($document, $owner, Carbon::parse('2026-10-20'));
        Sanctum::actingAs($owner);
        $response = $this->get("/api/billing-invoicing/documents/{$document->id}/pdf?download=1");
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $stored = $document->fresh();
        self::assertNotNull($stored->pdf_path);
        self::assertSame(hash('sha256', $response->getContent()), $stored->pdf_sha256);
        Storage::disk('local')->assertExists($stored->pdf_path);
        self::assertSame($profile->legal_name, $stored->issuer_snapshot['legal_name']);

        $this->expectException(LogicException::class);
        $item->fresh()->update(['description' => 'Changed after issue']);
    }

    public function test_pdf_endpoint_does_not_expose_another_organizations_document(): void
    {
        [$ownerA, , $ownerB] = $this->users();
        BillingProfile::create(['organization_id' => $ownerA->organization_id, 'legal_name' => 'A', 'permissions' => app(BillingPermissionService::class)->defaults()]);
        BillingProfile::create(['organization_id' => $ownerB->organization_id, 'legal_name' => 'B', 'permissions' => app(BillingPermissionService::class)->defaults()]);
        $document = BillingDocument::create(['organization_id' => $ownerA->organization_id, 'document_type' => 'invoice', 'currency' => 'INR', 'grand_total' => 100, 'created_by' => $ownerA->id]);

        Sanctum::actingAs($ownerB);
        $this->get("/api/billing-invoicing/documents/{$document->id}/pdf")->assertNotFound();
    }

    public function test_receipt_pdfs_represent_existing_payments_without_creating_revenue(): void
    {
        Storage::fake('local');
        [$owner] = $this->users();
        BillingProfile::create(['organization_id' => $owner->organization_id, 'legal_name' => 'Organization A', 'permissions' => app(BillingPermissionService::class)->defaults()]);
        $client = Client::create(['organization_id' => $owner->organization_id, 'created_by' => $owner->id, 'first_name' => 'Customer']);
        $deal = Deal::create(['organization_id' => $owner->organization_id, 'client_id' => $client->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id, 'title' => 'CRM Implementation', 'value' => 5000]);
        $payment = DealPayment::create(['organization_id' => $owner->organization_id, 'deal_id' => $deal->id, 'created_by' => $owner->id, 'amount' => 2500, 'payment_date' => '2026-10-20', 'payment_mode' => 'bank', 'txn_or_utr_number' => 'UTR-1']);
        $issuer = ['legal_name' => 'Organization A', 'contact_email' => 'billing@example.com', 'default_currency' => 'INR'];
        $customer = ['name' => 'Customer', 'company_name' => 'Customer Pvt Ltd'];
        $receipt = BillingReceipt::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $owner->organization_id,
            'client_id' => $client->id, 'deal_payment_id' => $payment->id, 'receipt_number' => 'RCP-1',
            'issuer_snapshot' => $issuer, 'customer_snapshot' => $customer,
            'payment_snapshot' => $payment->toArray() + ['currency' => 'INR', 'product_details' => $deal->title],
            'issued_at' => now(), 'issued_by' => $owner->id,
        ]);
        $consolidated = BillingConsolidatedReceipt::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $owner->organization_id,
            'client_id' => $client->id, 'receipt_number' => 'CR-1', 'period_start' => '2026-10-01',
            'period_end' => '2026-10-31', 'represented_total' => 2500, 'issuer_snapshot' => $issuer,
            'customer_snapshot' => $customer, 'issued_at' => now(), 'issued_by' => $owner->id,
        ]);
        BillingConsolidatedReceiptPayment::create(['billing_consolidated_receipt_id' => $consolidated->id, 'deal_payment_id' => $payment->id, 'amount_snapshot' => 2500]);
        Sanctum::actingAs($owner);

        $this->get("/api/billing-invoicing/receipts/{$receipt->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/billing-invoicing/consolidated-receipts/{$consolidated->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        self::assertSame(1, DealPayment::count(), 'Receipt generation must never duplicate the CRM payment.');
        self::assertNotNull($receipt->fresh()->pdf_sha256);
        self::assertNotNull($consolidated->fresh()->pdf_sha256);
    }

    private function users(): array
    {
        $orgA = Organization::create(['name' => 'Organization A', 'slug' => 'org-a', 'email' => 'a@example.com', 'timezone' => 'Asia/Kolkata']);
        $orgB = Organization::create(['name' => 'Organization B', 'slug' => 'org-b', 'email' => 'b@example.com', 'timezone' => 'Asia/Kolkata']);

        return [
            User::forceCreate(['organization_id' => $orgA->id, 'name' => 'Owner A', 'email' => 'owner-a@example.com', 'password' => 'password', 'role' => 'owner']),
            User::forceCreate(['organization_id' => $orgA->id, 'name' => 'Employee A', 'email' => 'employee-a@example.com', 'password' => 'password', 'role' => 'employee']),
            User::forceCreate(['organization_id' => $orgB->id, 'name' => 'Owner B', 'email' => 'owner-b@example.com', 'password' => 'password', 'role' => 'owner']),
        ];
    }

    private function profilePayload(string $legalName): array
    {
        return ['legal_name' => $legalName, 'country' => 'IN', 'default_currency' => 'INR', 'supported_currencies' => ['INR'], 'payment_terms_days' => 30, 'default_template' => 'classic', 'financial_year_start_month' => 4, 'tax_settings' => ['gst_enabled' => true, 'default_gst_rate' => 18, 'rounding_scale' => 2]];
    }
}
