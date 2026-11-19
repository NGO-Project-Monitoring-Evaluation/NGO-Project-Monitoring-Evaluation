<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Indicators with an actual value and calculated progress.
 * If $period is given, the value recorded for that period is used; otherwise the latest period.
 */
function indicator_progress_rows(?int $projectId = null, ?string $period = null, ?string $projectStatus = null): array
{
    $params = [];
    $join = 'v.indicator_id = i.indicator_id AND v.period_label = ';
    if ($period !== null && $period !== '') {
        $join .= '?';
        $params[] = $period;
    } else {
        $join .= '(SELECT MAX(v2.period_label) FROM indicator_values v2 WHERE v2.indicator_id = i.indicator_id)';
    }
    $sql = "SELECT i.*, p.title AS project_title, p.status AS project_status,
                   v.value_id, v.actual_value, v.period_label AS value_period
              FROM indicators i
              JOIN projects p ON p.project_id = i.project_id
              LEFT JOIN indicator_values v ON $join
             WHERE 1 = 1";
    if ($projectId) {
        $sql .= ' AND i.project_id = ?';
        $params[] = $projectId;
    }
    if ($projectStatus) {
        $sql .= ' AND p.status = ?';
        $params[] = $projectStatus;
    }
    $sql .= ' ORDER BY p.title, i.name';
    $rows = fetch_all($sql, $params);
    foreach ($rows as &$r) {
        $r['metrics']  = indicator_metrics($r['baseline'], $r['target'], $r['actual_value']);
        $r['progress'] = $r['metrics']['headline'];
    }
    return $rows;
}

function projects_list(): array
{
    [$sc, $sp] = project_scope('project_id');
    return fetch_all('SELECT project_id, title, status FROM projects WHERE 1 = 1' . $sc . ' ORDER BY title', $sp);
}

/** Link back to where the user came from, kept on the same site. */
function safe_next(string $next): string
{
    if ($next !== '' && $next[0] === '/' && substr($next, 0, 2) !== '//' && strpos($next, "\n") === false && strpos($next, "\r") === false) {
        if (BASE_URL === '' || strpos($next, BASE_URL . '/') === 0) {
            return $next;
        }
    }
    return '';
}

/**
 * Keep status and progress consistent (Chapter Three, Figure 3.13):
 * Completed means 100%; 100% means Completed; progress above 0 starts a Not started activity.
 * Returns [status, progress, message-or-null].
 */
function normalise_activity_state(string $status, int $progress): array
{
    $note = null;
    if ($status === 'Completed' && $progress !== 100) {
        $progress = 100;
        $note = 'Progress was set to 100% because the activity is completed.';
    } elseif ($progress === 100 && $status !== 'Completed') {
        $status = 'Completed';
        $note = 'The status was set to Completed because progress is 100%.';
    } elseif ($status === 'Not started' && $progress > 0) {
        $status = 'In progress';
        $note = 'The status was set to In progress because progress is above 0%.';
    }
    return [$status, $progress, $note];
}

/** SQL condition for activities that are delayed or past their end date and not completed. */
function flagged_sql(string $alias = 'a'): string
{
    return "($alias.status <> 'Completed' AND ($alias.status = 'Delayed' OR $alias.planned_end < ?))";
}
