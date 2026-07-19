<?php

namespace App\Services;

use App\Exports\GenericReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Requires:
 *   composer require maatwebsite/excel barryvdh/laravel-dompdf
 *
 * CSV is written with native fputcsv, so it works even without those
 * packages installed — only 'excel' and 'pdf' formats need them.
 */
class ReportExportService
{
    /**
     * @return string storage path (relative to the `public` disk) that
     *                should be saved into reports.file_url
     */
    public function export(string $type, string $format, Collection $rows, array $headings, string $dateSuffix): string
    {
        $filename = "reports/{$type}-{$dateSuffix}." . $this->extensionFor($format);

        return match ($format) {
            'csv'   => $this->exportCsv($rows, $headings, $filename),
            'excel' => $this->exportExcel($rows, $headings, $filename, $type),
            'pdf'   => $this->exportPdf($type, $rows, $headings, $filename),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    private function extensionFor(string $format): string
    {
        return match ($format) {
            'csv'   => 'csv',
            'excel' => 'xlsx',
            'pdf'   => 'pdf',
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    private function exportCsv(Collection $rows, array $headings, string $filename): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $headings);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('public')->put($filename, $csv);

        return $filename;
    }

    private function exportExcel(Collection $rows, array $headings, string $filename, string $title): string
    {
        $export = new GenericReportExport($rows, $headings, ucfirst($title) . ' Report');
        Excel::store($export, $filename, 'public');

        return $filename;
    }

    private function exportPdf(string $type, Collection $rows, array $headings, string $filename): string
    {
        $pdf = Pdf::loadView('reports.export-pdf', [
            'type'        => $type,
            'headings'    => $headings,
            'rows'        => $rows,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        Storage::disk('public')->put($filename, $pdf->output());

        return $filename;
    }
}