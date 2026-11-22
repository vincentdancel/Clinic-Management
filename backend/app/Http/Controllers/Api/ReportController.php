<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly ReportExportService $exportService,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reportService->filterOptions($request->user()),
        ]);
    }

    public function show(Request $request, string $type): JsonResponse
    {
        abort_unless(isset(ReportService::CATALOG[$type]), 404, 'Report type not found.');
        abort_unless(
            $this->reportService->canAccessReport($request->user(), $type),
            403,
            'You do not have permission to view this report.'
        );

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'period' => ['nullable', 'in:daily,weekly,monthly,yearly'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'doctor_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'method' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'integer'],
            'gender' => ['nullable', 'string', 'max:20'],
            'expiry_scope' => ['nullable', 'in:expired,expiring_30d,all_expired_and_soon'],
        ]);

        return response()->json([
            'data' => $this->reportService->generate($type, $filters),
        ]);
    }

    public function export(Request $request, string $type): Response|StreamedResponse
    {
        abort_unless(isset(ReportService::CATALOG[$type]), 404, 'Report type not found.');
        abort_unless(
            $request->user()?->hasPermission('reports.export') && $this->reportService->canAccessReport($request->user(), $type),
            403,
            'You do not have permission to export this report.'
        );

        $validated = $request->validate([
            'format' => ['required', 'in:pdf,xlsx'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'period' => ['nullable', 'in:daily,weekly,monthly,yearly'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'doctor_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'method' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'integer'],
            'gender' => ['nullable', 'string', 'max:20'],
            'expiry_scope' => ['nullable', 'in:expired,expiring_30d,all_expired_and_soon'],
        ]);

        $report = $this->reportService->generate($type, $validated);
        $userName = $request->user()?->name ?? 'System';

        if ($validated['format'] === 'pdf') {
            return $this->exportService->toPdf($report, $userName);
        }

        return $this->exportService->toExcel($report, $userName);
    }
}

