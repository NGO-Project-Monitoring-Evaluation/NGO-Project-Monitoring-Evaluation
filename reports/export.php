<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/reports.php';
require dirname(__DIR__) . '/includes/xlsx.php';
require dirname(__DIR__) . '/includes/pdf.php';
$user = require_perm('view_reports');

[$f, $errors] = report_filters();
if ($errors) { flash('danger', implode(' ', $errors)); redirect('reports/index.php'); }
$format = get('format') === 'pdf' ? 'pdf' : 'xlsx';

$report = build_report($f);
audit('Export', 'reports', null, $report['title'] . ' exported as ' . strtoupper($format) . ' (' . count($report['rows']) . ' rows). ' . $report['filters']);
$name = strtolower(str_replace([' ', '(', ')'], ['_', '', ''], $report['title'])) . '_' . date('Ymd_His');
if ($format === 'pdf') { send_report_pdf($report, $name); } else { send_report_download($report, $name); }
