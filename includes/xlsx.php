<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Minimal Excel (.xlsx) writer built on PHP's ZipArchive, so no extra library is needed.
 * $sheets = [ ['name' => 'Report', 'rows' => [[cell, cell], ...]] ]
 * A cell is a string, a number, or ['v' => value, 's' => 'bold'|'head'].
 */
function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_text(string $v): string
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '';
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_sheet_xml(array $rows): string
{
    $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="12" width="22" customWidth="1"/></cols><sheetData>';
    foreach ($rows as $r => $cells) {
        $out .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($cells) as $c => $cell) {
            $style = null;
            if (is_array($cell)) { $style = $cell['s'] ?? null; $cell = $cell['v'] ?? ''; }
            $ref = xlsx_col($c) . ($r + 1);
            $s = $style === 'head' ? ' s="2"' : ($style === 'bold' ? ' s="1"' : '');
            if ($cell === null || $cell === '') {
                if ($s) { $out .= '<c r="' . $ref . '"' . $s . '/>'; }
            } elseif (is_int($cell) || is_float($cell)) {
                $out .= '<c r="' . $ref . '"' . $s . '><v>' . (is_float($cell) ? rtrim(rtrim(sprintf('%.4F', $cell), '0'), '.') : $cell) . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xlsx_text((string) $cell) . '</t></is></c>';
            }
        }
        $out .= '</row>';
    }
    return $out . '</sheetData></worksheet>';
}

function xlsx_write(string $path, array $sheets): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $name = mb_substr(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $sh['name']) ?? 'Sheet', 0, 31);
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . xlsx_text($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', xlsx_sheet_xml($sh['rows']));
    }
    $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE1E6F0"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    return $zip->close();
}

/** Send a report to the browser as an Excel file (or CSV when the zip extension is missing). */
function send_report_download(array $report, string $baseName): void
{
    $rows = [[['v' => $report['title'], 's' => 'bold']], ['Filters: ' . $report['filters']], ['Generated by ' . $report['by'] . ' on ' . $report['generated']], []];
    foreach ($report['summary'] as [$label, $val]) { $rows[] = [['v' => $label, 's' => 'bold'], $val]; }
    $rows[] = [];
    $rows[] = array_map(fn($c) => ['v' => $c, 's' => 'head'], $report['columns']);
    foreach ($report['rows'] as $r) { $rows[] = $r; }

    $tmp = tempnam(sys_get_temp_dir(), 'ngo');
    if (xlsx_write($tmp, [['name' => $report['title'], 'rows' => $rows]])) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        return;
    }
    @unlink($tmp);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) { fputcsv($out, array_map(fn($c) => is_array($c) ? $c['v'] : $c, $r)); }
    fclose($out);
}
