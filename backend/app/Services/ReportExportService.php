<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function toPdf(array $report, string $generatedBy): Response
    {
        $filename = $this->filename($report['type'], 'pdf');

        $pdf = Pdf::loadView('reports.pdf', [
            'report' => $report,
            'generatedBy' => $generatedBy,
            'formattedGeneratedAt' => CarbonImmutable::parse($report['generated_at'])->format('M d, Y h:i A'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function toExcel(array $report, string $generatedBy): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $report['title']) ?: 'Report', 0, 31));

        $columns = $report['columns'] ?? [];
        $colCount = max(count($columns), 1);
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        // Title & metadata
        $sheet->setCellValue('A1', 'CLINIC CARE MANAGEMENT — '.strtoupper($report['title']));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F766E');
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $dateRange = ($report['filters']['date_from'] ?? null) || ($report['filters']['date_to'] ?? null)
            ? ($report['filters']['date_from'] ?? 'Start').' to '.($report['filters']['date_to'] ?? 'Today')
            : 'All Dates';
        $sheet->setCellValue('A2', "Generated: ".CarbonImmutable::parse($report['generated_at'])->format('Y-m-d H:i')." | By: {$generatedBy} | Range: {$dateRange}");
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setARGB('FF4B5563');

        // Summary KPIs
        $rowIdx = 4;
        if (! empty($report['summary'])) {
            $colIdx = 1;
            foreach ($report['summary'] as $kpi) {
                if ($colIdx > $colCount) {
                    break;
                }
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->setCellValue("{$colLetter}{$rowIdx}", $kpi['label']);
                $sheet->getStyle("{$colLetter}{$rowIdx}")->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FF6B7280');
                $sheet->setCellValue("{$colLetter}".($rowIdx + 1), $this->formatSummaryValue($kpi));
                $sheet->getStyle("{$colLetter}".($rowIdx + 1))->getFont()->setBold(true)->setSize(11);
                $colIdx++;
            }
            $rowIdx += 3;
        }

        // Table headers
        foreach ($columns as $idx => $col) {
            $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue("{$colLetter}{$rowIdx}", $col['label']);
            $sheet->getStyle("{$colLetter}{$rowIdx}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("{$colLetter}{$rowIdx}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F2937');
            if (($col['align'] ?? 'left') === 'right') {
                $sheet->getStyle("{$colLetter}{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
        $sheet->getRowDimension($rowIdx)->setRowHeight(22);
        $rowIdx++;

        // Table rows
        foreach ($report['rows'] ?? [] as $row) {
            foreach ($columns as $idx => $col) {
                $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
                $raw = $row[$col['key']] ?? '';
                $formatted = $this->formatCellValue($raw, $col['format'] ?? 'text', true);
                $sheet->setCellValue("{$colLetter}{$rowIdx}", $formatted);
                if (($col['align'] ?? 'left') === 'right') {
                    $sheet->getStyle("{$colLetter}{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                if (($col['format'] ?? '') === 'currency') {
                    $sheet->getStyle("{$colLetter}{$rowIdx}")->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            $rowIdx++;
        }

        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        $filename = $this->filename($report['type'], 'xlsx');

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function filename(string $type, string $ext): string
    {
        return sprintf('clinic-report-%s-%s.%s', $type, CarbonImmutable::now()->format('Ymd-His'), $ext);
    }

    /**
     * @param  array<string, mixed>  $kpi
     */
    private function formatSummaryValue(array $kpi): string|int|float
    {
        $val = $kpi['value'] ?? 0;

        return match ($kpi['format'] ?? 'text') {
            'currency' => 'PHP '.number_format((float) $val, 2),
            'percent' => number_format((float) $val, 1).'%',
            default => $val,
        };
    }

    private function formatCellValue(mixed $value, string $format, bool $forExcel = false): mixed
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($format) {
            'currency' => $forExcel ? (float) $value : 'PHP '.number_format((float) $value, 2),
            'percent' => $forExcel ? number_format((float) $value, 1).'%' : number_format((float) $value, 1).'%',
            'number' => $forExcel ? (is_numeric($value) ? (float) $value : $value) : (string) $value,
            'datetime' => is_string($value) && $value !== '—' ? CarbonImmutable::parse($value)->format('Y-m-d H:i') : $value,
            'date' => is_string($value) && $value !== '—' ? CarbonImmutable::parse($value)->format('Y-m-d') : $value,
            'status' => ucwords(str_replace('_', ' ', (string) $value)),
            default => (string) $value,
        };
    }
}

