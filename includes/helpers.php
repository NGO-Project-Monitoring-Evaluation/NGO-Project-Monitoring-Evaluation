<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/** Escape text for safe output in HTML. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function compute_base_url(): string
{
    $configured = cfg('base_url', '');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }
    // Method 1: the requested script's address minus its path inside the application folder.
    // Both paths go through realpath(), so symbolic links on the server do not confuse it.
    $root   = str_replace('\\', '/', realpath(APP_ROOT) ?: APP_ROOT);
    $file   = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if ($file !== '' && stripos($file, $root . '/') === 0) {
        $rel = substr($file, strlen($root) + 1);
        if ($rel !== '' && strlen($script) >= strlen($rel) + 1 && strcasecmp(substr($script, -strlen($rel) - 1), '/' . $rel) === 0) {
            return rtrim(substr($script, 0, -strlen($rel) - 1), '/');
        }
    }
    // Method 2: the application folder relative to the document root.
    $doc = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    if ($doc !== '' && stripos($root, $doc) === 0) {
        return rtrim(substr($root, strlen($doc)), '/');
    }
    return '';
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/* ---------- flash messages ---------- */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- input helpers ---------- */
function post(string $key, $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_array($v) ? '' : trim((string) $v);
}

function get(string $key, $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_array($v) ? '' : trim((string) $v);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function int_or_null($v): ?int
{
    $v = trim((string) $v);
    return ($v !== '' && ctype_digit($v)) ? (int) $v : null;
}

/** Valid calendar date in YYYY-MM-DD form. */
function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y) && $y >= 1900 && $y <= 2100;
}

function valid_phone(string $p): bool
{
    return (bool) preg_match('/^\+?[0-9]{7,15}$/', $p);
}

/** Remove spaces, dashes and brackets so phone numbers compare reliably. */
function normalise_phone(string $p): string
{
    return preg_replace('/[\s\-\(\)\.]/', '', $p) ?? '';
}

function valid_email(string $e): bool
{
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) && mb_strlen($e) <= 100;
}

function valid_decimal(string $v): bool
{
    return (bool) preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $v);
}

function fmt_date(?string $d): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : $d;
}

function fmt_datetime(?string $d): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d M Y, H:i', $t) : $d;
}

function fmt_number($n): string
{
    if ($n === null || $n === '') {
        return '';
    }
    $s = number_format((float) $n, 2, '.', ',');
    return rtrim(rtrim($s, '0'), '.');
}

/** Beneficiary codes look like NGBN001, NGBN002 ... (at least three digits). */
function beneficiary_code_from_id(int $id): string
{
    return 'NGBN' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
}

function days_overdue(?string $plannedEnd): int
{
    if (!$plannedEnd || !valid_date($plannedEnd) || $plannedEnd >= today()) {
        return 0;
    }
    return (int) (new DateTime($plannedEnd))->diff(new DateTime(today()))->days;
}

/** "62 households", "62%" - a number with its unit. */
function fmt_with_unit($n, string $unit): string
{
    return fmt_number($n) . ($unit === '%' ? '%' : ' ' . $unit);
}

/** Money in the configured currency, for example ₦1,250,000.00 */
function money($n): string
{
    return (string) cfg('currency', '₦') . number_format((float) $n, 2, '.', ',');
}

/** Compact money for dashboards: ₦12.50M, ₦850.0K. */
function money_short($n): string
{
    $v = (float) $n; $abs = abs($v); $c = (string) cfg('currency', '₦');
    if ($abs >= 1e9) return $c . number_format($v / 1e9, 2) . 'B';
    if ($abs >= 1e6) return $c . number_format($v / 1e6, 2) . 'M';
    if ($abs >= 1e3) return $c . number_format($v / 1e3, 1) . 'K';
    return $c . number_format($v, 0);
}

function valid_money(string $v): bool
{
    return (bool) preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v) && (float) $v > 0;
}

function pct($part, $whole, int $dp = 1): ?float
{
    return abs((float) $whole) > 1e-9 ? round((float) $part / (float) $whole * 100, $dp) : null;
}

function today(): string
{
    return date('Y-m-d');
}

function age_from_dob(?string $dob): ?int
{
    if (!$dob || !valid_date($dob)) {
        return null;
    }
    return (int) (new DateTime($dob))->diff(new DateTime('today'))->y;
}

function age_group(?int $age): string
{
    if ($age === null) return 'Not recorded';
    if ($age < 5)  return '0-4';
    if ($age < 18) return '5-17';
    if ($age < 36) return '18-35';
    if ($age < 60) return '36-59';
    return '60+';
}

/* ---------- indicator maths ---------- */
/**
 * progress = (actual - baseline) / (target - baseline) * 100
 * Returns null when no value exists or when the target equals the baseline.
 */
function progress_pct($baseline, $target, $actual): ?float
{
    if ($actual === null || $actual === '') {
        return null;
    }
    $den = (float) $target - (float) $baseline;
    if (abs($den) < 1e-9) {
        return null;
    }
    return round(((float) $actual - (float) $baseline) / $den * 100, 1);
}

function progress_class(?float $p): string
{
    if ($p === null) return 'secondary';
    if ($p >= 100)   return 'success';
    if ($p >= 60)    return 'primary';
    if ($p >= 30)    return 'warning';
    return 'danger';
}

/**
 * Everything the screens need to show for one indicator value.
 *  of_target       = actual / target x 100          (62 of 80 = 77.5%)
 *  since_baseline  = (actual - baseline) / (target - baseline) x 100   (the Chapter Two formula)
 * The headline figure is "% of target" when the indicator is meant to rise (target above baseline);
 * for an indicator meant to fall "% of target" has no meaning, so the baseline formula is used.
 */
function indicator_metrics($baseline, $target, $actual): array
{
    $m = ['has_value' => false, 'baseline' => (float) $baseline, 'target' => (float) $target, 'actual' => null,
          'of_target' => null, 'since_baseline' => null, 'headline' => null, 'basis' => '', 'status' => '', 'class' => 'secondary'];
    if ($actual === null || $actual === '') {
        return $m;
    }
    $b = (float) $baseline; $t = (float) $target; $a = (float) $actual;
    $m['has_value'] = true; $m['actual'] = $a;
    $m['since_baseline'] = progress_pct($b, $t, $a);
    $m['of_target'] = abs($t) > 1e-9 ? round($a / $t * 100, 1) : null;
    if ($t > $b && $t > 0 && $m['of_target'] !== null) { $m['headline'] = $m['of_target']; $m['basis'] = 'of target'; }
    else { $m['headline'] = $m['since_baseline']; $m['basis'] = 'toward target'; }
    $h = $m['headline'];
    if ($h === null)      { $m['status'] = 'Not calculable'; }
    elseif ($h > 100)     { $m['status'] = 'Target exceeded'; $m['class'] = 'success'; }
    elseif ($h >= 100)    { $m['status'] = 'Target met';      $m['class'] = 'success'; }
    else                  { $m['status'] = 'Below target';    $m['class'] = progress_class($h); }
    return $m;
}

/** The bar never grows past 100%; the real figure is written beside it; above 100% it says "Target exceeded". */
function indicator_progress_html(array $m): string
{
    if (!$m['has_value']) { return '<span class="small text-muted">No value yet</span>'; }
    if ($m['headline'] === null) { return '<span class="small text-muted">Not calculable (target equals baseline)</span>'; }
    $h = (float) $m['headline']; $w = max(0, min(100, $h));
    $html = '<div class="ind-progress"><div class="d-flex align-items-center gap-2">'
        . '<div class="progress flex-grow-1" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e((string) $w) . '" aria-valuetext="' . e(fmt_number($h)) . ' percent ' . e($m['basis']) . '">'
        . '<div class="progress-bar bg-' . e($m['class']) . '" style="width:' . e((string) $w) . '%"></div></div>'
        . '<span class="ind-pct">' . e(fmt_number($h)) . '%</span></div><div class="small text-muted">' . e($m['basis']) . '</div>';
    if ($m['status'] === 'Target exceeded' || $m['status'] === 'Target met') {
        $html .= '<span class="badge text-bg-success">' . e($m['status']) . '</span>';
    }
    if ($m['basis'] === 'of target' && $m['since_baseline'] !== null && abs($m['baseline']) > 1e-9) {
        $html .= '<div class="small text-muted">Progress from the baseline of ' . e(fmt_number($m['baseline'])) . ': ' . e(fmt_number($m['since_baseline'])) . '%</div>';
    }
    return $html . '</div>';
}

/* ---------- period labels ---------- */
function valid_period(string $label, string $frequency): bool
{
    switch ($frequency) {
        case 'Monthly':   return (bool) preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $label);
        case 'Quarterly': return (bool) preg_match('/^(19|20)\d{2}-Q[1-4]$/', $label);
        case 'Annual':    return (bool) preg_match('/^(19|20)\d{2}$/', $label);
    }
    return false;
}

function period_hint(string $frequency): string
{
    return ['Monthly' => 'YYYY-MM, for example 2026-03', 'Quarterly' => 'YYYY-Qn, for example 2026-Q1', 'Annual' => 'YYYY, for example 2026'][$frequency] ?? '';
}

/* ---------- activity status ---------- */
/** Flagged when marked Delayed, or past the planned end date and not completed. */
function activity_is_flagged(array $a): bool
{
    if (($a['status'] ?? '') === 'Completed') return false;
    if (($a['status'] ?? '') === 'Delayed')   return true;
    return !empty($a['planned_end']) && $a['planned_end'] < today();
}

function status_badge(string $status): string
{
    $map = ['Not started' => 'secondary', 'In progress' => 'primary', 'Completed' => 'success', 'Delayed' => 'danger',
            'Planned' => 'secondary', 'Ongoing' => 'primary', 'On hold' => 'warning'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . e($status) . '</span>';
}

function activity_status_badge(array $a): string
{
    $html = status_badge((string) $a['status']);
    if (activity_is_flagged($a) && $a['status'] !== 'Delayed') {
        $html .= ' <span class="badge text-bg-danger" title="Past the planned end date and not completed">Overdue</span>';
    }
    return $html;
}

function progress_bar(?float $pct, string $class = 'primary', bool $label = true): string
{
    $w = $pct === null ? 0 : max(0, min(100, $pct));
    return '<div class="progress" role="progressbar" aria-valuenow="' . e((string) $w) . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar bg-' . e($class) . '" style="width:' . e((string) $w) . '%">'
        . ($label && $pct !== null ? e(fmt_number($pct)) . '%' : '') . '</div></div>';
}

/* ---------- pagination ---------- */
function paginate(int $total, int $perPage = 25): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    return ['page' => $page, 'pages' => $pages, 'per' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pagination_html(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $q = $_GET;
    $link = function (int $n, string $label, bool $active = false, bool $disabled = false) use ($q) {
        $q['page'] = $n;
        return '<li class="page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '') . '"><a class="page-link" href="?'
            . e(http_build_query($q)) . '">' . $label . '</a></li>';
    };
    $html = '<nav aria-label="Pages"><ul class="pagination pagination-sm flex-wrap">';
    $html .= $link(max(1, $p['page'] - 1), '&laquo;', false, $p['page'] <= 1);
    for ($i = max(1, $p['page'] - 3); $i <= min($p['pages'], $p['page'] + 3); $i++) {
        $html .= $link($i, (string) $i, $i === $p['page']);
    }
    $html .= $link(min($p['pages'], $p['page'] + 1), '&raquo;', false, $p['page'] >= $p['pages']);
    return $html . '</ul></nav>';
}

const NIGERIAN_STATES = [
    'Abia','Adamawa','Akwa Ibom','Anambra','Bauchi','Bayelsa','Benue','Borno','Cross River','Delta','Ebonyi','Edo',
    'Ekiti','Enugu','Federal Capital Territory','Gombe','Imo','Jigawa','Kaduna','Kano','Katsina','Kebbi','Kogi','Kwara',
    'Lagos','Nasarawa','Niger','Ogun','Ondo','Osun','Oyo','Plateau','Rivers','Sokoto','Taraba','Yobe','Zamfara',
];
