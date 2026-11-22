<?php

namespace App\Jobs;

use App\Models\LeadImport;
use App\Services\LeadImports\ImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessLeadImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;
    public int $timeout = 840;

    public function __construct(public int $importId, public string $revision, public bool $validation = false) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('lead-import:'.$this->importId))->dontRelease()->expireAfter(900)];
    }

    public function handle(ImportProcessor $processor): void
    {
        $import = LeadImport::find($this->importId);
        if (! $import || ($import->mapping['revision'] ?? null) !== $this->revision) {
            return;
        }

        if ($this->validation) {
            try {
                $processor->validateFile($import);
            } catch (\App\Services\LeadImports\SpreadsheetException $error) {
                $processor->fail($import, $error->getMessage());
            } catch (\PhpOffice\PhpSpreadsheet\Exception $error) {
                $processor->fail($import, 'The workbook could not be read. Save a clean copy or export it as CSV.');
            } catch (Throwable $error) {
                Log::warning('Lead import validation retry', ['import_id' => $this->importId, 'error_class' => $error::class]);
                throw new \RuntimeException('Lead import validation could not finish.');
            }

            return;
        }

        try {
            $processor->import($import);
        } catch (Throwable $error) {
            Log::warning('Lead import processing retry', ['import_id' => $this->importId, 'error_class' => $error::class]);
            throw new \RuntimeException('Lead import processing could not finish.');
        }
        $import->refresh();
        if (in_array($import->status, ['completed', 'completed_errors'], true) && $import->file_path) {
            if (Storage::disk('local')->delete($import->file_path)) {
                $import->update(['file_path' => null]);
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        // Parser/DB exceptions can contain source values or filesystem paths.
        Log::warning('Lead import worker exhausted retries', ['import_id' => $this->importId, 'error_class' => $error ? $error::class : null]);
        DB::transaction(function () {
            $import = LeadImport::lockForUpdate()->find($this->importId);
            $states = $this->validation ? ['validating'] : ['queued', 'processing'];
            if ($import && ($import->mapping['revision'] ?? null) === $this->revision && in_array($import->status, $states, true)) {
                $import->update(['status' => 'failed', 'error' => 'Processing stopped. View the completed rows before uploading another file.', 'completed_at' => now()]);
            }
        });
    }
}
