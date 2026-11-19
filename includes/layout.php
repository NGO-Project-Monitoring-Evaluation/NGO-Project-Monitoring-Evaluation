<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/** Left-hand menu, shown according to the user's role. Items with no path are group headings. */
function menu_items(): array
{
    $items = [
        ['dashboard',     'Dashboard',          'dashboard.php',            true],
        ['projects',      'Projects',           'projects/index.php',       can('view_projects')],
        ['activities',    'Activities',         'activities/index.php',     can('view_operations')],
        ['beneficiaries', 'Beneficiaries',      'beneficiaries/index.php',  can('view_beneficiaries')],
        ['indicators',    'Indicators',         'indicators/index.php',     can('view_operations')],
        ['_fin',          'Finance',            '',                         can('view_finance')],
        ['finance',       'Finance overview',   'finance/index.php',        can('view_finance')],
        ['budget',        'Budget lines',       'finance/budget.php',       can('view_finance_detail')],
        ['expenditures',  'Expenditures',       'finance/expenditures.php', can('view_finance_detail')],
        ['commitments',   'Commitments',        'finance/commitments.php',  can('view_finance_detail')],
        ['funds',         'Donors and funds',   'finance/funds.php',        can('view_finance_detail')],
        ['alerts',        'Alerts',             'finance/alerts.php',       can('view_finance_detail')],
        ['_sys',          'System',             '',                         can('view_reports')],
        ['reports',       'Reports',            'reports/index.php',        can('view_reports')],
        ['users',         'Users',              'admin/users.php',          can('manage_users')],
        ['audit',         'Audit log',          'admin/audit.php',          can('view_audit')],
    ];
    return array_values(array_filter($items, fn($i) => $i[3]));
}

function sidebar_html(string $active): string
{
    $html = '<div class="sidebar-brand" title="' . e(cfg('app_full_name', cfg('app_name'))) . '">' . e(cfg('app_name')) . '<span class="brand-sub">Monitoring, Evaluation and Finance</span></div><nav class="nav flex-column sidebar-nav">';
    foreach (menu_items() as [$key, $label, $path]) {
        if ($path === '') {
            $html .= '<div class="nav-heading">' . e($label) . '</div>';
            continue;
        }
        $html .= '<a class="nav-link' . ($key === $active ? ' active' : '') . '" href="' . e(url($path)) . '">' . e($label) . '</a>';
    }
    $html .= '<a class="nav-link' . ($active === 'account' ? ' active' : '') . '" href="' . e(url('change_password.php')) . '">Change password</a>';
    return $html . '</nav>';
}

/** The trail shown at the top left of every page: Home / module / record / current page. */
function breadcrumb_items(string $title, string $active, ?array $crumbs): array
{
    if ($active === 'dashboard') {
        return [['Home', null]];
    }
    $items = [['Home', 'dashboard.php']];
    if ($crumbs !== null) {
        foreach ($crumbs as $c) { $items[] = [(string) $c[0], $c[1] ?? null]; }
    } else {
        foreach (menu_items() as [$key, $label, $path]) {
            if ($key === $active && $path !== '' && $label !== $title) { $items[] = [$label, $path]; }
        }
        $items[] = [$title, null];
    }
    $items[count($items) - 1][1] = null;      // the current page is not a link
    return $items;
}

function breadcrumb_html(array $items): string
{
    $html = '<nav aria-label="breadcrumb" class="crumbs"><ol class="breadcrumb mb-0">';
    foreach ($items as $i => [$label, $path]) {
        if ($i === count($items) - 1) {
            $html .= '<li class="breadcrumb-item active" aria-current="page" title="' . e($label) . '">' . e($label) . '</li>';
        } else {
            $html .= '<li class="breadcrumb-item"><a href="' . e(url((string) $path)) . '">' . e($label) . '</a></li>';
        }
    }
    return $html . '</ol></nav>';
}

/**
 * Start a page. $crumbs lists the trail after "Home" as [label, url] pairs, for example
 * [['Projects', 'projects/index.php'], ['Community Water Project']]. Leave it out for a simple module page.
 */
function render_header(string $title, string $active = '', ?array $crumbs = null): void
{
    $u = current_user();
    $sidebar = $u ? sidebar_html($active) : '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(cfg('app_name')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= $u ? 'app' : 'app-public' ?>">
<?php if ($u): ?>
<aside class="sidebar d-none d-lg-flex flex-column"><?= $sidebar ?></aside>
<div class="offcanvas offcanvas-start sidebar-offcanvas" tabindex="-1" id="menu" aria-label="Menu">
  <div class="offcanvas-header"><button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
  <div class="offcanvas-body p-0 d-flex flex-column"><?= $sidebar ?></div>
</div>
<div class="main">
  <header class="topbar d-flex align-items-center">
    <button class="btn btn-outline-primary btn-sm d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#menu" aria-controls="menu">Menu</button>
    <div class="flex-grow-1 crumbs-wrap"><?= breadcrumb_html(breadcrumb_items($title, $active, $crumbs)) ?></div>
    <span class="small text-muted d-none d-md-inline me-3"><?= e($u['full_name']) ?> &nbsp;|&nbsp; <?= e($u['role_name']) ?></span>
    <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Log out</button></form>
  </header>
  <main class="content">
    <h1 class="h4 page-title"><?= e($title) ?></h1>
<?php else: ?>
<main class="content-public">
<?php endif; ?>
<?php foreach (take_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert"><?= e($f['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
<?php endforeach; ?>
<?php
}

function render_footer(): void
{
    $u = current_user();
    ?>
  </main>
<?php if ($u): ?>
  <footer class="footer small text-muted"><?= e(cfg('app_full_name', cfg('app_name'))) ?></footer>
</div>
<?php endif; ?>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** Show validation errors above a form. */
function show_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="alert alert-danger" role="alert"><strong>Please fix the following:</strong><ul class="mb-0">';
    foreach ($errors as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    echo '</ul></div>';
}

/** Small helper for <option> lists. */
function options(array $rows, string $valueKey, string $labelKey, $selected = null, string $blank = ''): string
{
    $html = $blank !== '' ? '<option value="">' . e($blank) . '</option>' : '';
    foreach ($rows as $r) {
        $sel = ((string) $selected === (string) $r[$valueKey]) ? ' selected' : '';
        $html .= '<option value="' . e($r[$valueKey]) . '"' . $sel . '>' . e($r[$labelKey]) . '</option>';
    }
    return $html;
}

function simple_options(array $values, $selected = null, string $blank = ''): string
{
    $html = $blank !== '' ? '<option value="">' . e($blank) . '</option>' : '';
    foreach ($values as $v) {
        $html .= '<option value="' . e($v) . '"' . ((string) $selected === (string) $v ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $html;
}
