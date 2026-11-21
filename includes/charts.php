<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const CHART_COLOURS = ['#1F3864', '#2E6DA4', '#2A7F8E', '#D9902F', '#B5483F', '#7A6AA8', '#8A94A6'];

/** A donut chart drawn as inline SVG, followed by its legend. $items = [[label, value], ...] */
function chart_donut(array $items, string $centre = ''): string
{
    $items = array_values(array_filter($items, fn($i) => (float) $i[1] > 0));
    $total = array_sum(array_map(fn($i) => (float) $i[1], $items));
    if ($total <= 0) {
        return '<p class="text-muted small mb-0">No approved expenditure yet.</p>';
    }
    $svg = '<svg viewBox="0 0 42 42" width="190" height="190" role="img" aria-label="Expenditure by category"><circle cx="21" cy="21" r="15.91549431" fill="none" stroke="#e6eaf1" stroke-width="6"/>';
    $offset = 25.0; $legend = '';
    foreach ($items as $i => [$label, $value]) {
        $p = (float) $value / $total * 100; $c = CHART_COLOURS[$i % count(CHART_COLOURS)];
        $svg .= sprintf('<circle cx="21" cy="21" r="15.91549431" fill="none" stroke="%s" stroke-width="6" stroke-dasharray="%.3F %.3F" stroke-dashoffset="%.3F"/>', $c, $p, 100 - $p, $offset);
        $offset -= $p;
        $legend .= '<li><span class="legend-dot" style="background:' . $c . '"></span>' . e($label) . ': <strong>' . e(money_short($value)) . '</strong> (' . e(fmt_number(round($p, 1))) . '%)</li>';
    }
    if ($centre !== '') { $svg .= '<text x="21" y="22.5" text-anchor="middle" font-size="4.2" font-weight="700" fill="#1F3864">' . e($centre) . '</text>'; }
    return '<div class="d-flex flex-wrap align-items-center gap-3">' . $svg . '<ul class="list-unstyled small mb-0">' . $legend . '</ul></div>';
}

/** Paired horizontal bars (for example funds received against money spent). $rows = [[label, a, b], ...] */
function chart_pair_bars(array $rows, string $labelA, string $labelB): string
{
    $max = 0.0;
    foreach ($rows as $r) { $max = max($max, (float) $r[1], (float) $r[2]); }
    if ($max <= 0) { return '<p class="text-muted small mb-0">No funds recorded yet.</p>'; }
    $html = '<div class="small mb-2"><span class="legend-dot" style="background:#1F3864"></span>' . e($labelA) . ' &nbsp; <span class="legend-dot" style="background:#D9902F"></span>' . e($labelB) . '</div>';
    foreach ($rows as [$label, $a, $b]) {
        $html .= '<div class="mb-3"><div class="small fw-semibold text-truncate">' . e($label) . '</div>'
            . '<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:.8rem"><div class="progress-bar" style="width:' . round((float) $a / $max * 100, 1) . '%;background:#1F3864"></div></div><span class="small money" style="min-width:5.5rem">' . e(money_short($a)) . '</span></div>'
            . '<div class="d-flex align-items-center gap-2 mt-1"><div class="progress flex-grow-1" style="height:.8rem"><div class="progress-bar" style="width:' . round((float) $b / $max * 100, 1) . '%;background:#D9902F"></div></div><span class="small money" style="min-width:5.5rem">' . e(money_short($b)) . '</span></div></div>';
    }
    return $html;
}
