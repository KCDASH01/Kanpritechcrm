<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLeadImport;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Services\LeadImports\ImportAccess;
use App\Services\LeadImports\ImportFields;
use App\Services\LeadImports\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LeadImportController extends Controller
{
    public function __construct(private ImportAccess $access, private ImportFields $fields, private SpreadsheetReader $reader) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->access->visible($this->actor($request))->latest('id')->paginate(20)->through(fn ($import) => $this->summary($import));

        return $this->json($rows);
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $request->validate(['file' => ['required', 'file', 'max:'.config('lead_imports.max_kb'), 'extensions:csv,xlsx,xls']]);
        $file = $request->file('file');
        $format = strtolower($file->getClientOriginalExtension());
        $mimes = [
            'csv' => ['text/plain', 'text/csv', 'text/tab-separated-values', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/x-cfb', 'application/CDFV2', 'application/octet-stream'],
        ];
        if (! isset($mimes[$format]) || ! in_array($file->getMimeType(), $mimes[$format], true)) {
            throw ValidationException::withMessages(['file' => 'Choose a valid CSV, XLSX, or XLS spreadsheet.']);
        }
        try {
            $sheets = $this->reader->inspect($file->getRealPath(), $format);
        } catch (\App\Services\LeadImports\SpreadsheetException $error) {
            throw ValidationException::withMessages(['file' => $error->getMessage()]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be opened. Remove password protection, repair the file, or export it as CSV.']);
        }
        $path = $file->storeAs('lead-imports/'.$user->organization_id, Str::uuid().'.'.$format, 'local');
        abort_unless($path, 503, 'The upload could not be stored. Try again.');
        try {
            $import = LeadImport::create([
                'organization_id' => $user->organization_id, 'uploaded_by' => $user->id, 'uploader_name' => $user->name,
                'filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'file_path' => $path, 'status' => 'uploaded', 'format' => $format, 'file_size' => $file->getSize(),
                'sheets' => $sheets,
            ]);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return $this->json(['data' => $this->details($import, $user)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->actor($request);
        $import = $this->access->visible($user)->findOrFail($id);

        return $this->json(['data' => $this->details($import, $user)]);
    }

    public function validateFile(Request $request, int $id): JsonResponse
    {
        $user = $this->actor($request);
        $mapping = $request->validate([
            'sheet' => ['required', 'integer', 'min:0', 'max:19'],
            'columns' => ['required', 'array', 'max:80'],
            'columns.*' => ['required', 'distinct:strict', Rule::in(array_keys(ImportFields::LABELS))],
            'defaults' => ['nullable', 'array:service,market_type,client_type,business_type,source,status,currency,recurring_frequency,recurring_amount,recurring_start_date,recurring_end_type,recurring_end_date'],
            'defaults.*' => ['nullable', 'string', 'max:255'],
            'service_aliases' => ['nullable', 'array', 'max:100'],
            'service_aliases.*' => ['required', 'string', 'max:80'],
            'date_format' => ['required', Rule::in(['d/m', 'm/d'])],
            'fill_dates' => ['required', 'boolean'],
        ]);
        $import = DB::transaction(function () use ($id, $mapping, $user) {
            $import = $this->own($user, $id);
            abort_unless(in_array($import->status, ['uploaded', 'ready', 'failed'], true)
                && $import->file_path && ! $import->started_at, 409, 'This import is already processing or its file has expired.');
            $sheet = $import->sheets[$mapping['sheet']] ?? null;
            $fields = array_values($mapping['columns']);
            $errors = [];
            if (! isset($sheet['headers']) || array_diff(array_keys($mapping['columns']), array_keys($sheet['headers']))) {
                $errors['columns'] = 'Select a worksheet and use columns from its header row.';
            }
            if (! array_intersect($fields, ['first_name', 'full_name']) || ! array_intersect($fields, ['email', 'phone'])) {
                $errors['identity'] = 'Map a first or full name and at least one contact column.';
            }
            $mapping['defaults'] = array_replace($this->fields->defaults($user), array_filter($mapping['defaults'] ?? [], fn ($value) => $value !== null && $value !== ''));
            if (! in_array('service', $fields, true) && empty($mapping['defaults']['service'])) {
                $errors['service'] = 'Map a service column or choose a default service.';
            }
            $services = $this->fields->services($user);
            if (isset($mapping['defaults']['service']) && ! isset($services[$mapping['defaults']['service']])) {
                $errors['service'] = 'Choose an available default service.';
            }
            $aliases = [];
            foreach ($mapping['service_aliases'] ?? [] as $name => $choice) {
                $key = ImportFields::key((string) $name);
                if ($key === '' || strlen($key) > 255 || ! isset($services[$choice]) || isset($aliases[$key])) {
                    $errors['service_aliases'] = 'Each service alias needs a unique label and an available CRM service.';
                    break;
                }
                $aliases[$key] = $choice;
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $mapping['service_aliases'] = $aliases;
            $mapping['revision'] = (string) Str::uuid();
            $mapping['date_fallback'] = today()->toDateString();
            $import->update(['mapping' => $mapping, 'status' => 'validating', 'error' => null, 'completed_at' => null]);

            return $import;
        });

        return $this->dispatch($import, true);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $user = $this->actor($request);
        $data = $request->validate(['revision' => ['required', 'uuid']]);
        [$import, $shouldQueue] = DB::transaction(function () use ($user, $id, $data) {
            $import = $this->own($user, $id);
            abort_unless(($import->mapping['revision'] ?? null) === $data['revision'], 409, 'The preview changed. Review it before confirming.');
            if (in_array($import->status, ['queued', 'processing', 'completed', 'completed_errors'], true)) {
                return [$import, false];
            }
            abort_unless($import->status === 'ready' && $import->valid_rows > 0, 409, 'Validate the spreadsheet and fix any blocking issues before confirming.');
            $import->update(['status' => 'queued', 'error' => null]);

            return [$import, true];
        });

        return $shouldQueue ? $this->dispatch($import, false) : $this->json(['data' => $this->summary($import)]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $import = $this->access->visible($this->actor($request))->findOrFail($id);
        $filters = $request->validate(['filter' => ['nullable', Rule::in(['ready', 'invalid', 'duplicate', 'warnings', 'created', 'failed'])]]);
        abort_if(in_array($import->status, ['uploaded', 'validating'], true), 409, 'The preview is not available yet.');
        $filter = $filters['filter'] ?? null;
        $rows = $import->rows()->orderBy('source_row')
            ->when($filter === 'warnings', fn ($query) => $query->where('warning', true))
            ->when($filter && $filter !== 'warnings', fn ($query) => $query->where('status', $filter))
            ->paginate(50)->through(fn ($row) => $this->previewRow($row));

        return $this->json($rows);
    }

    public function errors(Request $request, int $id): StreamedResponse
    {
        $import = $this->access->visible($this->actor($request))->findOrFail($id);
        abort_if(in_array($import->status, ['uploaded', 'validating'], true), 409, 'The validation report is not ready.');

        return response()->streamDownload(function () use ($import) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Row', 'Name', 'Email', 'Phone', 'Service', 'Category', 'Error or suggested correction'], ',', '"', '');
            foreach ($import->rows()->where(fn ($query) => $query->whereIn('status', ['invalid', 'duplicate', 'failed'])->orWhere('warning', true))
                ->lazyById(200) as $row) {
                $preview = $this->previewRow($row)['values'];
                $status = $row->warning && in_array($row->status, ['created', 'ready'], true) ? 'warning' : $row->status;
                $values = [$row->source_row, $preview['name'], $preview['email'] ?? '', $preview['phone'] ?? '',
                    $preview['service'] ?? '', $status, implode(' ', $row->issues ?? [])];
                fputcsv($file, array_map(fn ($value) => $this->safeCsv((string) $value), $values), ',', '"', '');
            }
            fclose($file);
        }, 'lead-import-'.$import->id.'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->actor($request);

        return response()->streamDownload(function () {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Leads');
            $sheet->fromArray(array_values(ImportFields::LABELS), null, 'A1', true);
            foreach (['A2' => '2026-10-07', 'B2' => 'Taylor', 'C2' => 'Example', 'E2' => 'taylor@example.test',
                'F2' => '+12025550123', 'M2' => 'United States', 'N2' => 'INTERNATIONAL', 'P2' => 'Website Development',
                'Q2' => 'ONE_TIME', 'S2' => 'New'] as $cell => $value) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            }
            $sheet->getStyle('A1:AD1')->getFont()->setBold(true);
            $sheet->getStyle('F:F')->getNumberFormat()->setFormatCode('@');
            $sheet->freezePane('A2');
            $help = $book->createSheet()->setTitle('Instructions');
            $help->fromArray([
                ['Field', 'Guidance'],
                ['Name', 'Enter First Name or Full Name. Full Name is split at the first space.'],
                ['Contact', 'Supply Email, Phone, or both. Format phone numbers as text. Include + and the country code.'],
                ['Service / Product', 'Use a CRM catalogue name, service code, or choose a default or alias when mapping.'],
                ['Lead Date', 'Use YYYY-MM-DD (2026-10-07), 07-Oct-26, or choose your slash date order.'],
                ['Market Type', 'Supply DOMESTIC or INTERNATIONAL when the country cannot be derived.'],
                ['Historical statuses', 'Use Current Status, Call Status, and WhatsApp Status for descriptive notes.'],
                ['Recurring leads', 'Supply frequency, amount, start date, and end type. A fixed end also requires a date.'],
                ['Ownership', 'All imported leads are assigned to the signed-in uploader.'],
            ]);
            $help->getColumnDimension('A')->setWidth(24);
            $help->getColumnDimension('B')->setWidth(105);
            (new Xlsx($book))->setPreCalculateFormulas(false)->save('php://output');
            $book->disconnectWorksheets();
        }, 'growneq-lead-import.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }

    private function dispatch(LeadImport $import, bool $validation): JsonResponse
    {
        try {
            ProcessLeadImport::dispatch($import->id, $import->mapping['revision'], $validation)->onConnection(config('lead_imports.queue_connection'))->onQueue('lead-imports');
        } catch (Throwable $error) {
            // The dispatch may already have reached the queue. Keep the revision in place
            // so the scheduler can recover it safely if the job never arrives.
            \Illuminate\Support\Facades\Log::warning('Lead import dispatch will be retried', [
                'import_id' => $import->id, 'error_class' => $error::class,
            ]);
        }

        return $this->json(['data' => $this->summary($import->refresh())], 202);
    }

    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }

    private function actor(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($this->access->canImport($user), 403, 'Lead import requires an active Business or Enterprise membership.');

        return $user;
    }

    private function own(\App\Models\User $user, int $id): LeadImport
    {
        return $this->access->visible($user)->where('uploaded_by', $user->id)->lockForUpdate()->findOrFail($id);
    }

    private function summary(LeadImport $import): array
    {
        return $import->only([
            'id', 'uploaded_by', 'uploader_name', 'filename', 'format', 'file_size', 'status',
            'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'warning_rows', 'created_rows', 'failed_rows',
            'error', 'started_at', 'completed_at', 'created_at',
        ]);
    }

    private function details(LeadImport $import, \App\Models\User $user): array
    {
        $sheets = $import->sheets;
        foreach ($sheets as &$sheet) {
            if (isset($sheet['headers'])) {
                $sheet += $this->fields->suggestions($sheet['headers']);
            }
        }
        unset($sheet);

        return $this->summary($import) + [
            'organization' => $user->organization->name,
            'can_edit' => $import->uploaded_by === $user->id && $import->file_path && ! $import->started_at,
            'sheets' => $sheets,
            'mapping' => $import->mapping,
            'fields' => ImportFields::LABELS,
            'services' => collect($this->fields->services($user))->map(fn ($service) => $service['label']),
            'defaults' => $this->fields->defaults($user),
        ];
    }

    private function previewRow(LeadImportRow $row): array
    {
        $data = $row->payload ?? [];
        $preview = $data['preview'] ?? [];
        $attributes = $data['attributes'] ?? [];
        $values = $attributes + $preview + ['lead_date' => $data['lead_date'] ?? ''];
        $values['name'] = trim(($values['first_name'] ?? '').' '.($values['last_name'] ?? '')) ?: ($values['full_name'] ?? '');

        return ['source_row' => $row->source_row, 'status' => $row->status, 'warning' => $row->warning,
            'issues' => $row->issues, 'values' => array_intersect_key($values, array_flip([
                'name', 'email', 'phone', 'country', 'service', 'types', 'status', 'lead_date',
            ]))];
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^(?:[\t\r\n]|[\p{Z}\p{C}\s]*[=+\-@＝＋－＠])/u', $value) ? "'".$value : $value;
    }
}
