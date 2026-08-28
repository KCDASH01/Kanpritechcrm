<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExporter
{
    /** @param list<string> $headers */
    /** @param iterable<int, list<string|int|float|null>> $rows */
    public static function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn ($value) => $value === null ? '' : (string) $value,
                    $row
                ));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @param list<string> $headers */
    /** @param iterable<int, list<string|int|float|null>> $rows */
    public static function excel(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
            echo '<thead><tr>';
            foreach ($headers as $header) {
                echo '<th>' . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8') . '</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    $value = $cell === null ? '' : (string) $cell;
                    echo '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                echo '</tr>';
            }

            echo '</tbody></table></body></html>';
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }
}
