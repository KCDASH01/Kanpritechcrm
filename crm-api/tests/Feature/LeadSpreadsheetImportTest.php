<?php

namespace Tests\Feature;

use App\Jobs\ProcessLeadImport;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LeadConversionRatioService;
use App\Services\LeadImports\ImportProcessor;
use App\Services\LeadImports\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadSpreadsheetImportTest extends TestCase
{
    private const URL = '/api/leads/imports';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('P', 32)), 'lead_imports.queue_connection' => 'sync']);
        $this->app->forgetInstance('encrypter');
        Storage::fake('local');
        DB::connection()->getPdo()->sqliteCreateFunction('FIELD', function ($value, ...$values) {
            $index = array_search($value, $values, true);

            return $index === false ? 0 : $index + 1;
        }, -1);
        $this->artisan('migrate', [
            '--path' => dirname(__DIR__, 3).'/admin-dashboard/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0);
    }

    #[DataProvider('workbookFormats')]
    public function test_excel_formats_read_dates_merges_text_numbers_and_formulas(string $format): void
    {
        config(['lead_imports.batch_size' => 2]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Assigned');
        $sheet->fromArray([
            ['Assigned Date', 'Name', 'Phone/WhatsApp', 'Email'],
            ['2026-10-07', 'Alice Example', '0012025550123'],
            [null, 'Morgan Example', null, '=1+2'],
            [null, 'Jordan Example', '+12025550456'],
        ]);
        $sheet->setCellValueExplicit('C2', '0012025550123', DataType::TYPE_STRING);
        $sheet->setCellValue('A2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTimeImmutable('2026-10-07')));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->setCellValueExplicit('C4', 1234567890123456, DataType::TYPE_NUMERIC);
        $sheet->mergeCells('A2:A4');
        $book->createSheet()->setTitle('Instructions')->fromArray([['Section', 'Explanation']]);
        $path = Storage::disk('local')->path('fixture.'.$format);
        $writer = $format === 'xls' ? new Xls($book) : new Xlsx($book);
        $writer->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();

        $reader = app(SpreadsheetReader::class);
        $sheets = $reader->inspect($path, $format);
        self::assertSame(['Assigned', 'Instructions'], array_column($sheets, 'name'));
        $rows = iterator_to_array($reader->rows($path, $format, $sheets[0], 2, dateColumn: 0));
        self::assertSame('0012025550123', $rows[2][2]['text']);
        self::assertSame('2026-10-07', $rows[3][0]['text']);
        self::assertSame('2026-10-07', $rows[4][0]['text']);
        self::assertSame('unsafe', $rows[3][3]['kind']);
        self::assertSame('large_number', $rows[4][2]['kind']);
    }

    public static function workbookFormats(): array
    {
        return [['xlsx'], ['xls']];
    }

    public function test_csv_preview_permission_duplicates_and_imported_lead_ownership(): void
    {
        [$organization, $employee] = $this->membership('sales', 'employee');
        $admin = $this->user($organization, 'admin');
        [$other, $outsider] = $this->membership('other', 'owner');
        $peer = $this->user($organization, 'employee', 'peer');
        $this->existing($employee, 'existing@example.test', '+12025550101');
        $this->existing($outsider, 'shared@example.test', '+12025550102');
        Sanctum::actingAs($employee);

        $csv = "\xEF\xBB\xBFAssigned Date;Name;Email;Phone/WhatsApp;Country;Service;Current Status;Call Status;Website;Assigned to;Total Leads;WhatsApp Status;Status\n"
            ."October - 2026;;;;;;;;;;\n"
            ."07/10/2026;Taylor Example;shared@example.test;;United States;Website Development;Historical status;Left a message;https://example.test;99999;4;Replied on WhatsApp;Future Lead\n"
            .";Morgan Example;;0012025550156;India;Website Development;;\"Call notes\nSecond line\";https://example.test;;\n"
            .";Repeated;shared@example.test;;India;Website Development;;;;;\n"
            .";Existing;existing@example.test;;India;Website Development;;;;;\n"
            .";Invalid;bad-email;;India;Website Development;;;;;\n"
            .";Bad phone;good@example.test;123;India;Website Development;;;;;\n"
            .";Missing contact;;;India;Website Development;;;;;\n"
            .";;missing-name@example.test;;India;Website Development;;;;;\n"
            .";Unknown service;unknown@example.test;;India;Unrecognized;;;;;\n"
            .";Bad date;date@example.test;;India;Website Development;;;;;\n";
        $csv = str_replace(';Bad date;', "31/02/2026;Bad date;", $csv);
        $upload = $this->postJson(self::URL, [
            'file' => UploadedFile::fake()->createWithContent('sales.csv', $csv),
            'organization_id' => $other->id, 'assigned_to' => $admin->id,
        ])->assertCreated();
        $id = $upload->json('data.id');
        $url = self::URL.'/'.$id;
        $upload->assertJsonPath('data.sheets.0.map.0', 'lead_date')->assertJsonPath('data.sheets.0.map.1', 'full_name')
            ->assertJsonPath('data.sheets.0.map.11', 'whatsapp_status')
            ->assertJsonMissingPath('data.sheets.0.map.9')->assertJsonMissingPath('data.sheets.0.map.10');
        self::assertSame(2, Lead::count());
        $this->postJson($url.'/confirm', ['revision' => fake()->uuid()])->assertConflict();
        $this->postJson($url.'/validate', ['sheet' => 0, 'columns' => [9 => 'assigned_to'], 'date_format' => 'd/m', 'fill_dates' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('columns.9');
        $this->postJson($url.'/validate', [
            'sheet' => 0, 'columns' => $upload->json('data.sheets.0.map'), 'date_format' => 'd/m', 'fill_dates' => true,
        ])->assertAccepted();
        $preview = $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'ready');
        self::assertSame(2, $preview->json('data.valid_rows'), json_encode($this->getJson($url.'/preview')->json('data')));
        $preview->assertJsonPath('data.invalid_rows', 6)->assertJsonPath('data.duplicate_rows', 2)->assertJsonPath('data.warning_rows', 1);
        $revision = $preview->json('data.mapping.revision');
        $this->getJson($url.'/preview?filter=warnings')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('total', 1);
        $this->getJson($url.'/preview?filter=ready')->assertOk()->assertJsonPath('total', 2);
        $this->get($url.'/errors')->assertOk()->assertDownload('lead-import-'.$id.'-report.csv');
        self::assertSame(2, Lead::count());

        Sanctum::actingAs($peer);
        $this->getJson($url)->assertNotFound();
        Sanctum::actingAs($outsider);
        $this->getJson($url)->assertNotFound();
        $this->get($url.'/errors')->assertNotFound();
        $this->getJson('/api/leads?import_id='.$id)->assertNotFound();
        Sanctum::actingAs($admin);
        $this->getJson($url)->assertOk();
        $this->postJson($url.'/confirm', ['revision' => $revision])->assertNotFound();

        Sanctum::actingAs($employee);
        $this->postJson($url.'/confirm', ['revision' => $revision, 'assigned_to' => $admin->id])->assertAccepted();
        $this->postJson($url.'/confirm', ['revision' => $revision])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.created_rows', 2)->assertJsonPath('data.status', 'completed_errors');
        $this->getJson('/api/leads?import_id='.$id)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.summary.domestic', 1)->assertJsonPath('meta.summary.international', 1);
        $this->assertDatabaseHas('leads', ['organization_id' => $organization->id, 'created_by' => $employee->id,
            'assigned_to' => $employee->id, 'status' => 'future_lead', 'email' => 'shared@example.test']);
        $importedLead = Lead::where('organization_id', $organization->id)->where('email', 'shared@example.test')->firstOrFail();
        self::assertSame('2026-10-07', $importedLead->lead_date->toDateString());
        self::assertStringContainsString('Historical status', $importedLead->notes);
        self::assertStringContainsString('Replied on WhatsApp', $importedLead->notes);
        $this->assertDatabaseHas('leads', ['organization_id' => $organization->id, 'assigned_to' => $employee->id, 'phone' => '0012025550156']);
        self::assertStringContainsString("Call notes\nSecond line", Lead::where('phone', '0012025550156')->firstOrFail()->notes);
        self::assertSame(4, Lead::count());
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('deal_payments', 0);
        $this->assertDatabaseCount('lead_timeline', 2);
        $conversion = app(LeadConversionRatioService::class);
        $period = $conversion->resolvePeriod($employee, ['conversion_period' => 'all_time']);
        $metrics = $conversion->calculate($organization->id, collect([$employee]), $period);
        self::assertSame(3, $metrics['members'][$employee->id]['assigned_leads']);
        self::assertSame(0, $metrics['members'][$employee->id]['converted_leads']);
    }

    public function test_worker_rechecks_permissions_and_duplicates_after_preview(): void
    {
        [$organization, $admin] = $this->membership('queue', 'admin');
        Sanctum::actingAs($admin);
        $first = $this->readyCsv('first@example.test');
        $second = $this->readyCsv('later@example.test');
        Queue::fake();
        $this->postJson(self::URL.'/'.$first['id'].'/confirm', ['revision' => $first['mapping']['revision']])->assertAccepted();
        $this->postJson(self::URL.'/'.$second['id'].'/confirm', ['revision' => $second['mapping']['revision']])->assertAccepted();
        $this->existing($admin, 'first@example.test', '+12025550999');
        (new ProcessLeadImport($first['id'], $first['mapping']['revision']))->handle(app(ImportProcessor::class));
        $this->assertDatabaseHas('lead_imports', ['id' => $first['id'], 'duplicate_rows' => 1, 'created_rows' => 0]);
        $admin->update(['is_active' => false]);
        (new ProcessLeadImport($second['id'], $second['mapping']['revision']))->handle(app(ImportProcessor::class));
        (new ProcessLeadImport($second['id'], $second['mapping']['revision']))->handle(app(ImportProcessor::class));
        $this->assertDatabaseHas('lead_imports', ['id' => $second['id'], 'status' => 'failed', 'created_rows' => 0]);
        $this->assertDatabaseMissing('leads', ['organization_id' => $organization->id, 'email' => 'later@example.test']);
    }

    public function test_old_worker_cannot_overwrite_a_newer_preview_or_its_state(): void
    {
        [$organization, $actor] = $this->membership('revision', 'employee');
        Sanctum::actingAs($actor);
        $ready = $this->readyCsv('revision@example.test');
        $id = $ready['id'];
        $oldRevision = $ready['mapping']['revision'];

        Queue::fake();
        $this->postJson(self::URL.'/'.$id.'/validate', [
            'sheet' => 0, 'columns' => ['full_name', 'email', 'country', 'service'], 'date_format' => 'm/d', 'fill_dates' => false,
        ])->assertAccepted();
        $updated = LeadImport::findOrFail($id);
        self::assertNotSame($oldRevision, $updated->mapping['revision']);

        $stale = new ProcessLeadImport($id, $oldRevision, true);
        $stale->handle(app(ImportProcessor::class));
        $stale->failed(new \RuntimeException('Synthetic failure'));
        $this->assertDatabaseHas('lead_imports', ['id' => $id, 'status' => 'validating']);

        $current = new ProcessLeadImport($id, $updated->mapping['revision'], true);
        $current->handle(app(ImportProcessor::class));
        $this->assertDatabaseHas('lead_imports', ['id' => $id, 'status' => 'ready', 'valid_rows' => 1]);
        $this->assertDatabaseCount('leads', 0);

        Subscription::where('organization_id', $organization->id)->update(['plan' => 'free']);
        $this->getJson('/api/leads?import_id='.$id)->assertForbidden();
    }

    public function test_spreadsheet_headers_are_bounded_and_long_notes_survive_import(): void
    {
        [, $actor] = $this->membership('notes', 'owner');
        Sanctum::actingAs($actor);
        $wide = str_repeat('Header', 100).",Email
Example,example@example.test";
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('header.csv', $wide)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $note = str_repeat('Historical note. ', 260).'Last sentence.';
        $csv = "Name,Email,Country,Service,Current Status
Taylor Example,notes@example.test,India,Website Development,{$note}";
        $id = $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('notes.csv', $csv)])
            ->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/'.$id.'/validate', [
            'sheet' => 0, 'columns' => ['full_name', 'email', 'country', 'service', 'current_status'], 'date_format' => 'd/m', 'fill_dates' => false,
        ])->assertAccepted();
        $revision = $this->getJson(self::URL.'/'.$id)->assertJsonPath('data.valid_rows', 1)->json('data.mapping.revision');
        $this->postJson(self::URL.'/'.$id.'/confirm', ['revision' => $revision])->assertAccepted();
        self::assertStringContainsString($note, Lead::sole()->notes);
    }

    public function test_database_worker_processes_confirmed_rows_and_retry_keeps_the_same_records(): void
    {
        [$organization, $admin] = $this->membership('database-queue', 'admin');
        Sanctum::actingAs($admin);
        config(['lead_imports.queue_connection' => 'lead-imports', 'lead_imports.batch_size' => 1]);
        $csv = "Name,Email,Country,Service
Example Name,first@example.test,India,Website Development
\u{FEFF}=SUM(1+1),invalid,India,Website Development";
        $id = $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('queue.csv', $csv)])
            ->assertCreated()->json('data.id');
        $url = self::URL.'/'.$id;
        $this->postJson($url.'/validate', [
            'sheet' => 0, 'columns' => ['full_name', 'email', 'country', 'service'], 'date_format' => 'd/m', 'fill_dates' => false,
        ])->assertAccepted()->assertJsonPath('data.status', 'validating');
        $this->assertDatabaseCount('jobs', 1);
        Artisan::call('queue:work', ['connection' => 'lead-imports', '--queue' => 'lead-imports', '--once' => true, '--sleep' => 0]);
        $job = $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'ready')->json('data');
        $report = $this->get($url.'/errors')->assertOk()->streamedContent();
        self::assertStringContainsString("'\u{FEFF}=SUM(1+1)", $report);

        $this->postJson($url.'/confirm', ['revision' => $job['mapping']['revision']])->assertAccepted()->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseCount('leads', 0);
        Artisan::call('queue:work', ['connection' => 'lead-imports', '--queue' => 'lead-imports', '--once' => true, '--sleep' => 0]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.created_rows', 1)->assertJsonPath('data.invalid_rows', 1);
        (new ProcessLeadImport($id, $job['mapping']['revision']))->handle(app(ImportProcessor::class));
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', ['organization_id' => $organization->id, 'assigned_to' => $admin->id, 'email' => 'first@example.test']);
    }

    public function test_worker_resumes_after_a_committed_batch_without_recreating_leads_or_events(): void
    {
        [, $admin] = $this->membership('resume', 'admin');
        Sanctum::actingAs($admin);
        config(['lead_imports.batch_size' => 1]);
        $csv = "Name,Email,Country,Service
First Lead,first@example.test,India,Website Development
Second Lead,second@example.test,India,Website Development";
        $id = $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('resume.csv', $csv)])
            ->assertCreated()->json('data.id');
        $url = self::URL.'/'.$id;
        $this->postJson($url.'/validate', [
            'sheet' => 0, 'columns' => ['full_name', 'email', 'country', 'service'], 'date_format' => 'd/m', 'fill_dates' => false,
        ])->assertAccepted();
        $revision = $this->getJson($url)->assertJsonPath('data.valid_rows', 2)->json('data.mapping.revision');
        Queue::fake();
        $this->postJson($url.'/confirm', ['revision' => $revision])->assertAccepted();

        $interrupt = true;
        Lead::creating(function (Lead $lead) use (&$interrupt) {
            if ($interrupt && $lead->email === 'second@example.test') {
                throw new \RuntimeException('Synthetic worker interruption');
            }
        });
        $job = new ProcessLeadImport($id, $revision);
        try {
            $job->handle(app(ImportProcessor::class));
            self::fail('Expected the second batch to be interrupted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Lead import processing could not finish.', $error->getMessage());
        }
        $this->assertDatabaseHas('lead_imports', ['id' => $id, 'status' => 'processing', 'created_rows' => 1]);
        $this->assertDatabaseCount('lead_timeline', 1);

        $interrupt = false;
        $job->handle(app(ImportProcessor::class));
        $job->handle(app(ImportProcessor::class));
        $this->assertDatabaseHas('lead_imports', ['id' => $id, 'status' => 'completed', 'created_rows' => 2]);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('lead_timeline', 2);
    }

    public function test_rejects_invalid_uploads_and_serves_excel_template(): void
    {
        [, $admin] = $this->membership('files', 'owner');
        Sanctum::actingAs($admin);
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('empty.csv', '')])->assertUnprocessable();
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('wrong.xlsx', 'Name,Email')])->assertUnprocessable();
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('bad.txt', 'Name,Email')])->assertUnprocessable();
        config(['lead_imports.max_kb' => 1]);
        $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('large.csv', str_repeat('a', 2000))])->assertUnprocessable();
        $template = $this->get(self::URL.'/template')->assertOk()->assertDownload('growneq-lead-import.xlsx')->streamedContent();
        self::assertStringStartsWith("PK\x03\x04", $template);
        Storage::disk('local')->put('template.xlsx', $template);
        $sheets = app(SpreadsheetReader::class)->inspect(Storage::disk('local')->path('template.xlsx'), 'xlsx');
        self::assertSame(['Leads', 'Instructions'], array_column($sheets, 'name'));
        $this->postJson('/api/leads/bulk-import', ['leads' => [['first_name' => 'Bypass']]])->assertGone();
    }

    #[DataProvider('benchmarkSizes')]
    public function test_indexed_import_benchmark(string $format, int $count): void
    {
        if (getenv('GROWNEQ_RUN_IMPORT_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set GROWNEQ_RUN_IMPORT_BENCHMARK=1 to measure the indexed import fixtures.');
        }
        [$organization, $admin] = $this->membership('benchmark', 'admin');
        Sanctum::actingAs($admin);
        $duplicates = intdiv($count, 20);
        for ($index = 1; $index <= $duplicates; $index++) {
            $this->existing($admin, "LEAD{$index}@EXAMPLE.TEST", '+12025550101');
        }

        $headers = ['Assigned Date', 'Name', 'Email', 'Country', 'Service', 'Call Status', 'Assigned to', 'Total Leads'];
        $csv = $format === 'csv' ? fopen('php://temp', 'w+b') : null;
        $book = $format === 'xlsx' ? new Spreadsheet : null;
        if ($csv) {
            fputcsv($csv, $headers, ',', '"', '');
        } else {
            $book->getActiveSheet()->setTitle('Assigned Leads')->fromArray($headers);
            $book->createSheet()->setTitle('Instructions')->fromArray([['Label', 'Content'], ['Synthetic data', 'Performance fixture']]);
        }
        for ($index = 1; $index <= $count; $index++) {
            $row = ['2026-10-07', "Synthetic Lead {$index}", "lead{$index}@example.test", 'India', 'Website Development',
                'Synthetic historical call note', 'Ignored Employee', 0];
            if ($csv) {
                fputcsv($csv, $row, ',', '"', '');
            } else {
                $book->getSheet(0)->fromArray($row, null, 'A'.($index + 1));
            }
        }
        if ($csv) {
            rewind($csv);
            $content = stream_get_contents($csv);
            fclose($csv);
        } else {
            $path = Storage::disk('local')->path('benchmark.xlsx');
            (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
            $book->disconnectWorksheets();
            $content = file_get_contents($path);
            unset($book);
        }

        $response = $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('benchmark.'.$format, $content)])
            ->assertCreated();
        $id = $response->json('data.id');
        memory_reset_peak_usage();
        $start = hrtime(true);
        $this->postJson(self::URL.'/'.$id.'/validate', [
            'sheet' => 0, 'columns' => $response->json('data.sheets.0.map'), 'date_format' => 'd/m', 'fill_dates' => false,
        ])->assertAccepted();
        $validated = hrtime(true);
        $ready = $count - $duplicates;
        $import = $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.valid_rows', $ready)
            ->assertJsonPath('data.duplicate_rows', $duplicates)->json('data');
        $this->postJson(self::URL.'/'.$id.'/confirm', ['revision' => $import['mapping']['revision']])->assertAccepted();
        $finished = hrtime(true);
        $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.created_rows', $ready)
            ->assertJsonPath('data.duplicate_rows', $duplicates)->assertJsonPath('data.failed_rows', 0);
        self::assertSame($ready, Lead::where('organization_id', $organization->id)->where('source', 'csv')->count());
        fwrite(STDERR, sprintf("Indexed benchmark: %s %d rows, %d duplicates, validate %.2fs, import %.2fs, peak %.1f MiB
",
            $format, $count, $duplicates, ($validated - $start) / 1e9, ($finished - $validated) / 1e9, memory_get_peak_usage(true) / 1048576));
    }

    public static function benchmarkSizes(): array
    {
        return [
            'CSV 100' => ['csv', 100], 'CSV 500' => ['csv', 500], 'CSV 1000' => ['csv', 1000],
            'CSV 5000' => ['csv', 5000], 'CSV 10000' => ['csv', 10000],
            'XLSX 1000' => ['xlsx', 1000], 'XLSX 10000' => ['xlsx', 10000],
        ];
    }

    private function readyCsv(string $email): array
    {
        $content = "Name,Email,Country,Service\nExample Name,{$email},India,Website Development";
        $id = $this->postJson(self::URL, ['file' => UploadedFile::fake()->createWithContent('sheet.csv', $content)])
            ->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/'.$id.'/validate', [
            'sheet' => 0, 'columns' => ['full_name', 'email', 'country', 'service'], 'date_format' => 'd/m', 'fill_dates' => false,
        ])->assertAccepted();

        return $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.valid_rows', 1)->json('data');
    }

    private function membership(string $slug, string $role): array
    {
        $organization = Organization::create(['name' => ucfirst($slug), 'slug' => $slug, 'country' => 'India', 'is_active' => true]);
        $user = $this->user($organization, $role);
        Subscription::create(['organization_id' => $organization->id, 'user_id' => $user->id,
            'plan' => 'enterprise', 'status' => 'active', 'is_active' => true, 'start_date' => today()]);
        return [$organization, $user];
    }

    private function user(Organization $organization, string $role, ?string $label = null): User
    {
        return User::create(['organization_id' => $organization->id, 'role' => $role, 'name' => ucfirst($label ?? $role),
            'email' => ($label ?? $role).'@'.$organization->slug.'.test', 'password' => 'test-password', 'is_active' => true]);
    }

    private function existing(User $user, string $email, string $phone): void
    {
        Lead::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'assigned_to' => $user->id,
            'first_name' => 'Existing', 'email' => $email, 'phone' => $phone, 'types' => 'others']);
    }
}
