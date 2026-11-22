<?php

namespace App\Services\LeadImports;

use App\Models\LeadImport;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadCreationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ImportProcessor
{
    public function __construct(
        private ImportAccess $access,
        private ImportFields $fields,
        private SpreadsheetReader $reader,
        private ContactKeys $contacts,
        private LeadCreationService $creation,
    ) {}

    public function validateFile(LeadImport $import): void
    {
        if ($import->status !== 'validating') {
            return;
        }
        $actor = $this->access->uploader($import);
        if (! $actor) {
            $this->fail($import, 'The original uploader no longer has permission to import leads.');

            return;
        }

        $setup = $import->mapping;
        $sheet = $import->sheets[$setup['sheet']];
        $services = $this->fields->services($actor);
        $start = $sheet['header_row'] + 1;
        $dateColumn = array_search('lead_date', $setup['columns'], true);
        $path = Storage::disk('local')->path($import->file_path);
        $totals = ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'duplicate_rows' => 0, 'warning_rows' => 0];
        $seen = ['email' => [], 'phone' => [], 'website' => []];
        $previousDate = null;
        $batch = [];
        $import->rows()->delete();

        foreach ($this->reader->rows($path, $import->format, $sheet, $start, null, $dateColumn === false ? null : (int) $dateColumn) as $rowNumber => $cells) {
            if ($rowNumber > config('lead_imports.max_rows') + 50) {
                throw new SpreadsheetException('The worksheet exceeds the row limit. Split it into smaller files.');
            }
            $mapped = $this->fields->mapRow($cells, $setup['columns'], $previousDate, $setup['fill_dates'] && $import->format === 'csv');
            if (! $mapped) {
                continue;
            }
            if (++$totals['total_rows'] > config('lead_imports.max_rows')) {
                throw new SpreadsheetException('Too many lead rows. Split the file and upload the parts separately.');
            }
            $preview = array_map(fn ($value) => mb_substr($value, 0, 300), array_intersect_key($mapped['values'], array_flip([
                'full_name', 'first_name', 'last_name', 'email', 'phone', 'country', 'service', 'status', 'lead_date',
            ])));
            $issues = $mapped['issues'];
            try {
                $resolved = $issues ? null : $this->fields->prepare($actor, $mapped['values'], $setup, $services);
            } catch (ValidationException $error) {
                $issues = array_map(fn ($messages) => $messages[0], $error->errors());
                $resolved = null;
            }
            $batch[] = ['source_row' => $rowNumber, 'resolved' => $resolved, 'preview' => $preview, 'issues' => $issues];
            if (count($batch) >= $this->batchSize()) {
                $this->savePreview($import, $batch, $seen, $totals);
                $batch = [];
            }
        }
        $this->savePreview($import, $batch, $seen, $totals);
        $import->update($totals + ['status' => 'ready', 'error' => null]);
    }

    private function savePreview(LeadImport $import, array $batch, array &$seen, array &$totals): void
    {
        if (! $batch) {
            return;
        }
        $resolved = array_filter(array_column($batch, 'resolved'));
        $existing = $this->contacts->existing($import->organization_id, $resolved, true);
        $records = [];
        foreach ($batch as $row) {
            $status = 'invalid';
            $issues = $row['issues'];
            $warning = false;
            $payload = ['preview' => $row['preview']];
            if ($row['resolved']) {
                $payload += $row['resolved'];
                $keys = $this->contacts->keys($row['resolved']['attributes']);
                $duplicates = $this->duplicateIssues($keys, $existing) + $this->duplicateIssues($keys, $seen, true);
                if ($duplicates) {
                    $status = 'duplicate';
                    $issues = $duplicates;
                } else {
                    $status = 'ready';
                    $warning = isset($seen['website'][$keys['website']]) || isset($existing['website'][$keys['website']]);
                    if ($warning) {
                        $issues['website'] = 'Another lead has this website. Check the contacts before confirming.';
                    }
                    $this->contacts->remember($seen, $keys);
                }
            }

            $totals[match ($status) {
                'ready' => 'valid_rows', 'duplicate' => 'duplicate_rows', default => 'invalid_rows',
            }]++;
            $totals['warning_rows'] += (int) $warning;
            $records[] = [
                'lead_import_id' => $import->id, 'source_row' => $row['source_row'], 'status' => $status,
                'payload' => encrypt(json_encode($payload, JSON_THROW_ON_ERROR), false),
                'issues' => json_encode($issues, JSON_THROW_ON_ERROR), 'warning' => $warning,
            ];
        }
        // Keep encrypted bindings below packet-size limits for notes-heavy worksheets.
        foreach (array_chunk($records, 50) as $rows) {
            DB::table('lead_import_rows')->insert($rows);
        }
        $import->touch();
    }

    public function import(LeadImport $import): void
    {
        while (DB::transaction(fn () => $this->processBatch($import->id, $import->mapping['revision']), 3)) {
            // Each commit advances the durable row states as well as the lead records.
        }
    }

    private function processBatch(int $id, string $revision): bool
    {
        $import = LeadImport::lockForUpdate()->findOrFail($id);
        if (($import->mapping['revision'] ?? null) !== $revision || ! in_array($import->status, ['queued', 'processing'], true)) {
            return false;
        }
        Organization::whereKey($import->organization_id)->lockForUpdate()->firstOrFail();
        $actor = $this->access->uploader($import, lock: true);
        if (! $actor) {
            $this->fail($import, 'The original uploader no longer has permission to import leads.');

            return false;
        }
        $import->status = 'processing';
        $import->started_at ??= now();
        $batch = $import->rows()->where('status', 'ready')->orderBy('id')->limit($this->batchSize())->get();
        if ($batch->isEmpty()) {
            $import->status = $import->failed_rows || $import->invalid_rows || $import->duplicate_rows ? 'completed_errors' : 'completed';
            $import->completed_at = now();
            $import->save();

            return false;
        }

        $capacity = $this->creation->remainingCapacity($actor);
        $seen = $this->contacts->existing($import->organization_id, $batch->map(fn ($row) => $row->payload)->all());
        $department = $actor->departments()->value('departments.id');
        foreach ($batch as $row) {
            $data = $row->payload;
            $keys = $this->contacts->keys($data['attributes']);
            $issues = $this->duplicateIssues($keys, $seen);
            if ($issues) {
                $import->warning_rows -= (int) $row->warning;
                $row->update(['status' => 'duplicate', 'issues' => $issues, 'warning' => false]);
                $import->duplicate_rows++;
                continue;
            }
            try {
                if ($capacity === 0) {
                    throw ValidationException::withMessages(['plan' => 'The organization has reached its lead limit.']);
                }
                if (($data['attributes']['client_type'] ?? null) === 'EXISTING') {
                    $client = $this->creation->visibleClients($actor)->find($data['attributes']['client_id']);
                    if (! $client || $this->contacts->keys($client->getAttributes()) !== $keys) {
                        throw ValidationException::withMessages(['client_type' => 'The linked Client changed after preview. Validate a new import.']);
                    }
                }
                $attributes = $data['attributes'] + ['department_id' => $department];
                $lead = $this->creation->create($actor, $attributes, $data['lead_date'], $id, organizationLocked: true);
                $row->update(['status' => 'created', 'lead_id' => $lead->id]);
                $this->contacts->remember($seen, $keys);
                $import->created_rows++;
                if ($capacity !== null) {
                    $capacity--;
                }
            } catch (ValidationException $error) {
                $row->update(['status' => 'failed', 'issues' => array_map(fn ($messages) => $messages[0], $error->errors())]);
                $import->failed_rows++;
            } catch (\Illuminate\Database\QueryException $error) {
                if (! in_array((string) $error->getCode(), ['22001', '22003', '23000'], true)) {
                    throw $error;
                }
                // The nested creation transaction rolls back only the rejected row.
                $row->update(['status' => 'failed', 'issues' => ['record' => 'The CRM rejected this record. Review its values and upload it again.']]);
                $import->failed_rows++;
            }
        }
        $import->save();

        return true;
    }

    private function duplicateIssues(array $keys, array $seen, bool $sheet = false): array
    {
        $issues = [];
        foreach (['email', 'phone'] as $field) {
            if ($keys[$field] && isset($seen[$field][$keys[$field]])) {
                $issues[$field] = $sheet
                    ? "This {$field} matches an earlier row in the file."
                    : "This {$field} matches an existing lead in this organization.";
            }
        }

        return $issues;
    }

    public function fail(LeadImport $import, string $reason): void
    {
        $import->update(['status' => 'failed', 'error' => $reason, 'completed_at' => now()]);
    }

    private function batchSize(): int
    {
        return min(500, max(1, (int) config('lead_imports.batch_size')));
    }
}
