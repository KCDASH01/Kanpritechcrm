<?php

namespace App\Services\LeadImports;

use Generator;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use ZipArchive;

class SpreadsheetReader
{
    public function inspect(string $path, string $format): array
    {
        $this->assertFormat($path, $format);
        $sheets = $format === 'csv'
            ? [['worksheetName' => 'CSV', 'totalRows' => config('lead_imports.max_rows') + 50, 'totalColumns' => config('lead_imports.max_columns')]]
            : $this->excel($format)->listWorksheetInfo($path);
        if (count($sheets) > 20) {
            throw new SpreadsheetException('Upload a workbook with at most 20 worksheets.');
        }

        $options = [];
        foreach ($sheets as $sheet) {
            $worksheet = ['name' => $sheet['worksheetName'], 'rows' => $sheet['totalRows'], 'width' => $sheet['totalColumns']];
            if ($worksheet['width'] > config('lead_imports.max_columns') || $worksheet['rows'] > config('lead_imports.max_rows') + 50) {
                $options[] = ['name' => $worksheet['name'], 'error' => 'This sheet exceeds the row or column limit. Remove unused rows and columns or split the file.'];
                continue;
            }
            $header = null;
            foreach ($this->rows($path, $format, $worksheet, 1, 25) as $number => $row) {
                $values = array_map(fn ($cell) => $cell['text'], $row);
                if ($header === null) {
                    if (count(array_filter($values, fn ($value) => $value !== '')) < 2) {
                        continue;
                    }
                    if (array_filter($values, fn ($label) => mb_strlen($label) > 255)) {
                        throw new SpreadsheetException('Limit worksheet column headings to 255 characters.');
                    }
                    $header = $number;
                    $worksheet['headers'] = $values;
                } elseif (count(array_filter($values, fn ($value) => $value !== '')) >= 2) {
                    $worksheet['sample'] = array_map(fn ($value) => mb_substr($value, 0, 120), $values);
                    break;
                }
            }
            $options[] = $header === null
                ? ['name' => $worksheet['name'], 'error' => 'Add a header row with at least two named columns.']
                : $worksheet + ['header_row' => $header, 'sample' => []];
        }
        if (! array_filter($options, fn ($sheet) => isset($sheet['headers']))) {
            throw new SpreadsheetException($options[0]['error'] ?? 'The file contains no usable worksheets.');
        }

        return $options;
    }

    public function rows(string $path, string $format, array $sheet, int $start, ?int $stop = null, ?int $dateColumn = null): Generator
    {
        if ($format === 'csv') {
            yield from $this->csvRows($path, $start, $stop);

            return;
        }

        $stop ??= $sheet['rows'];
        $step = min(500, max(1, (int) config('lead_imports.batch_size')));
        $dateAnchors = [];
        for ($first = $start; $first <= $stop; $first += $step) {
            $last = min($stop, $first + $step - 1);
            $reader = $this->excel($format);
            $reader->setLoadSheetsOnly($sheet['name']);
            $reader->setReadFilter(new class($first, $last) implements IReadFilter {
                public function __construct(private int $first, private int $last) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->first && $row <= $this->last;
                }
            });
            $book = $reader->load($path);
            try {
                $worksheet = $book->getSheet(0);
                $merged = [];
                if ($dateColumn !== null) {
                    foreach ($worksheet->getMergeCells() as $range) {
                        [[$fromColumn, $fromRow], [$toColumn, $toRow]] = Coordinate::rangeBoundaries($range);
                        if ($fromColumn === $dateColumn + 1 && $toColumn === $fromColumn && $fromRow < $toRow) {
                            for ($row = max($fromRow, $first); $row <= min($toRow, $last); $row++) {
                                $merged[$row] = $fromRow;
                            }
                        }
                    }
                }
                for ($row = $first; $row <= $last; $row++) {
                    $values = [];
                    for ($column = 0; $column < $sheet['width']; $column++) {
                        $address = Coordinate::stringFromColumnIndex($column + 1).$row;
                        $values[] = $worksheet->cellExists($address)
                            ? $this->excelCell($worksheet->getCell($address))
                            : ['text' => '', 'kind' => 'text'];
                    }
                    if ($dateColumn !== null && isset($merged[$row])) {
                        $anchor = $merged[$row];
                        if ($anchor === $row) {
                            $dateAnchors = [$anchor => $values[$dateColumn]];
                        } elseif (isset($dateAnchors[$anchor]) && $values[$dateColumn]['text'] === '') {
                            $values[$dateColumn] = $dateAnchors[$anchor] + ['inherited' => true];
                        }
                    }
                    yield $row => $values;
                }
            } finally {
                $book->disconnectWorksheets();
            }
        }
    }

    private function excelCell(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): array
    {
        $type = $cell->getDataType();
        if ($type === DataType::TYPE_FORMULA || $type === DataType::TYPE_ERROR) {
            return ['text' => '', 'kind' => 'unsafe'];
        }
        $value = $cell->getValue();
        if ($type === DataType::TYPE_NUMERIC && is_numeric($value)) {
            if (Date::isDateTime($cell, $value)) {
                return ['text' => Date::excelToDateTimeObject((float) $value)->format('Y-m-d'), 'kind' => 'date'];
            }
            $format = $cell->getStyle()->getNumberFormat()->getFormatCode() ?? '';
            $text = preg_match('/^0{2,30}$/D', $format) ? NumberFormat::toFormattedString($value, $format) : (string) $value;

            return ['text' => $text, 'kind' => abs((float) $value) >= 1e15 ? 'large_number' : 'number'];
        }

        return ['text' => trim($value instanceof RichText ? $value->getPlainText() : (string) $value), 'kind' => 'text'];
    }

    private function csvRows(string $path, int $start, ?int $stop): Generator
    {
        $handle = fopen($path, 'rb');
        try {
            $head = fread($handle, 3);
            if ($head === "\xEF\xBB\xBF") {
                $offset = 3;
            } elseif (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
                $offset = 2;
                fseek($handle, $offset);
                stream_filter_append($handle, str_starts_with($head, "\xFF\xFE") ? 'convert.iconv.UTF-16LE/UTF-8' : 'convert.iconv.UTF-16BE/UTF-8', STREAM_FILTER_READ);
            } else {
                $offset = 0;
            }
            if ($offset !== 2) {
                fseek($handle, $offset);
            }
            $sample = [];
            for ($index = 0; $index < 20 && ($line = fgets($handle, 8192)) !== false; $index++) {
                $sample[] = $line;
            }
            $delimiter = $this->delimiter($sample);
        } finally {
            fclose($handle);
        }

        $handle = fopen($path, 'rb');
        try {
            fseek($handle, $offset);
            if ($offset === 2) {
                stream_filter_append($handle, str_starts_with($head, "\xFF\xFE") ? 'convert.iconv.UTF-16LE/UTF-8' : 'convert.iconv.UTF-16BE/UTF-8', STREAM_FILTER_READ);
            }
            $number = 0;
            while (($stop === null || $number < $stop) && ($fields = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                if ($number < $start) {
                    continue;
                }
                if (count($fields) > config('lead_imports.max_columns')) {
                    throw new SpreadsheetException('The file has too many columns. Remove unused columns and try again.');
                }
                yield $number => array_map(function ($field) {
                    $text = trim((string) $field);
                    if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                        throw new SpreadsheetException('Export the CSV as UTF-8 or UTF-16 and try again.');
                    }

                    return ['text' => $text, 'kind' => 'text'];
                }, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    private function delimiter(array $sample): string
    {
        $scores = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $widths = array_map(fn ($line) => count(str_getcsv($line, $candidate, '"', '')), $sample);
            $frequency = array_count_values(array_filter($widths, fn ($width) => $width > 1));
            $scores[$candidate] = $frequency ? max($frequency) : 0;
        }
        arsort($scores);

        return array_key_first($scores);
    }

    private function excel(string $format): Xlsx|Xls
    {
        $reader = $format === 'xlsx' ? new Xlsx : new Xls;
        $reader->setIncludeCharts(false);
        $reader->setReadEmptyCells(false);

        return $reader;
    }

    private function assertFormat(string $path, string $format): void
    {
        $prefix = file_get_contents($path, false, null, 0, 4096);
        $ole = str_starts_with($prefix, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        $zip = str_starts_with($prefix, "PK\x03\x04");
        if ($format === 'xls' && $ole) {
            return;
        }
        if ($format === 'csv' && ! $zip && ! $ole
            && (! str_contains($prefix, "\0") || str_starts_with($prefix, "\xFF\xFE") || str_starts_with($prefix, "\xFE\xFF"))) {
            return;
        }
        if ($format !== 'xlsx' || ! $zip) {
            throw new SpreadsheetException('The file contents do not match the file extension. An encrypted workbook must be exported without a password.');
        }
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new SpreadsheetException('The XLSX archive is corrupt.');
        }
        try {
            if ($archive->numFiles > 2000 || $archive->locateName('xl/workbook.xml') === false) {
                throw new SpreadsheetException('The file is not a supported XLSX workbook.');
            }
            $total = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $total += $entry['size'];
                if ($total > config('lead_imports.max_unpacked_bytes') || ($entry['encryption_method'] ?? 0) !== 0
                    || preg_match('~(?:vbaproject|xl/(?:embeddings|externalLinks|drawings|media)/)~i', $entry['name'])) {
                    throw new SpreadsheetException('Remove macros, linked objects, embedded media, or encryption, or export the sheet as CSV.');
                }
                if (str_ends_with($entry['name'], '.rels')) {
                    $xml = $archive->getFromIndex($index);
                    if (stripos($xml, 'TargetMode') !== false) {
                        $relationships = new \DOMDocument;
                        if (! $relationships->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $relationships->doctype) {
                            throw new SpreadsheetException('The workbook contains invalid relationships.');
                        }
                        foreach ($relationships->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
                            if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0
                                && ! str_ends_with($relationship->getAttribute('Type'), '/hyperlink')) {
                                throw new SpreadsheetException('Remove external workbook links or export the worksheet as CSV.');
                            }
                        }
                    }
                }
            }
        } finally {
            $archive->close();
        }
    }
}
