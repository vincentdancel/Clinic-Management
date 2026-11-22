<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #1f2937; font-size: 11px; margin: 20px; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 14px; }
        .clinic { font-size: 16px; font-weight: bold; color: #0f766e; }
        .title { font-size: 14px; font-weight: bold; margin-top: 4px; color: #111827; }
        .meta { font-size: 10px; color: #6b7280; margin-top: 4px; }
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 0 -6px 14px; }
        .summary-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px 10px; width: 25%; }
        .summary-label { font-size: 9px; text-transform: uppercase; color: #64748b; margin-bottom: 3px; }
        .summary-value { font-size: 13px; font-weight: bold; color: #0f172a; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th { background: #0f766e; color: #ffffff; text-align: left; padding: 7px 6px; font-size: 10px; text-transform: uppercase; }
        table.data td { border-bottom: 1px solid #e5e7eb; padding: 6px; font-size: 10px; }
        table.data tr:nth-child(even) td { background: #f9fafb; }
        .right { text-align: right !important; }
        .footer { margin-top: 16px; font-size: 9px; color: #6b7280; text-align: right; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .empty { text-align: center; padding: 24px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <div class="clinic">CLINIC CARE MANAGEMENT SYSTEM</div>
        <div class="title">{{ $report['title'] }}</div>
        <div class="meta">
            {{ $report['description'] }}<br>
            Generated: {{ $formattedGeneratedAt }} &middot; Prepared by: {{ $generatedBy }}
            @if(!empty($report['filters']['date_from']) || !empty($report['filters']['date_to']))
                &middot; Date Range: {{ $report['filters']['date_from'] ?? 'Start' }} to {{ $report['filters']['date_to'] ?? 'Today' }}
            @endif
        </div>
    </div>

    @if(!empty($report['summary']))
        <table class="summary-table">
            <tr>
                @foreach($report['summary'] as $kpi)
                    <td class="summary-card">
                        <div class="summary-label">{{ $kpi['label'] }}</div>
                        <div class="summary-value">
                            @if(($kpi['format'] ?? '') === 'currency')
                                PHP {{ number_format((float) $kpi['value'], 2) }}
                            @elseif(($kpi['format'] ?? '') === 'percent')
                                {{ number_format((float) $kpi['value'], 1) }}%
                            @else
                                {{ number_format((float) $kpi['value'], is_float($kpi['value']) ? 2 : 0) }}
                            @endif
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if(empty($report['rows']))
        <div class="empty">No records matched the selected filters.</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach($report['columns'] as $col)
                        <th class="{{ ($col['align'] ?? 'left') === 'right' ? 'right' : '' }}">{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        @foreach($report['columns'] as $col)
                            @php
                                $val = $row[$col['key']] ?? '—';
                                $fmt = $col['format'] ?? 'text';
                            @endphp
                            <td class="{{ ($col['align'] ?? 'left') === 'right' ? 'right' : '' }}">
                                @if($val === null || $val === '')
                                    —
                                @elseif($fmt === 'currency')
                                    PHP {{ number_format((float) $val, 2) }}
                                @elseif($fmt === 'percent')
                                    {{ number_format((float) $val, 1) }}%
                                @elseif($fmt === 'datetime' && $val !== '—')
                                    {{ \Carbon\CarbonImmutable::parse($val)->format('Y-m-d H:i') }}
                                @elseif($fmt === 'date' && $val !== '—')
                                    {{ \Carbon\CarbonImmutable::parse($val)->format('Y-m-d') }}
                                @elseif($fmt === 'status')
                                    {{ ucwords(str_replace('_', ' ', (string) $val)) }}
                                @else
                                    {{ $val }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Clinic Care Management &middot; {{ count($report['rows']) }} record(s)
    </div>
</body>
</html>

