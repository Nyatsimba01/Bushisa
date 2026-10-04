<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/report_handler.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/logger.php';

require_login();

if (!is_moderator()) {
    redirect('/discover.php');
}

$adminId = get_current_user_id();
$error = null;
$success = null;
$allowedStatusFilters = ['all', 'pending', 'reviewed', 'dismissed', 'action_taken'];
$status_filter = clean_enum((string) ($_GET['status'] ?? 'all'), $allowedStatusFilters) ?? 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $reportId = clean_int($_POST['report_id'] ?? null);
    $action = clean_enum((string) ($_POST['action'] ?? ''), ['dismiss', 'reviewed', 'suspend_user']);

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($adminId === null) {
        $error = 'Unable to identify the current admin.';
    } elseif ($reportId === null || $reportId < 1 || $action === null) {
        $error = 'Invalid report action.';
    } else {
        $report = admin_get_report($pdo, $reportId);

        if ($report === null) {
            $error = 'Report not found.';
        } elseif ($action === 'dismiss') {
            if (update_report_status($pdo, $reportId, 'dismissed')) {
                log_action($pdo, $adminId, 'dismiss_report:' . $reportId, $_SERVER['REMOTE_ADDR'] ?? null);
                log_to_file('Admin ' . $adminId . ' dismissed report ' . $reportId, 'INFO');
                $success = 'Report dismissed.';
            } else {
                $error = 'Unable to dismiss report.';
            }
        } elseif ($action === 'reviewed') {
            if (update_report_status($pdo, $reportId, 'reviewed')) {
                log_action($pdo, $adminId, 'review_report:' . $reportId, $_SERVER['REMOTE_ADDR'] ?? null);
                log_to_file('Admin ' . $adminId . ' marked report ' . $reportId . ' as reviewed', 'INFO');
                $success = 'Report marked as reviewed.';
            } else {
                $error = 'Unable to mark report as reviewed.';
            }
        } elseif ($action === 'suspend_user') {
            $reportedUserId = isset($report['reported_user_id']) ? (int) $report['reported_user_id'] : 0;

            if ($reportedUserId < 1) {
                $error = 'Report does not reference a valid user.';
            } elseif (suspend_user($pdo, $reportedUserId, $adminId) && update_report_status($pdo, $reportId, 'action_taken')) {
                log_action($pdo, $adminId, 'action_report_suspend_user:' . $reportId, $_SERVER['REMOTE_ADDR'] ?? null);
                log_to_file('Admin ' . $adminId . ' suspended user ' . $reportedUserId . ' from report ' . $reportId, 'INFO');
                $success = 'Reported user suspended and report marked as action taken.';
            } else {
                $error = 'Unable to suspend reported user.';
            }
        }
    }
}

$reports = admin_get_reports($pdo, $status_filter);
$csrfToken = generate_csrf_token();

/**
 * Fetch one report for action handling.
 *
 * @return array<string, mixed>|null
 */
function admin_get_report(PDO $pdo, int $report_id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM reports WHERE id = :report_id LIMIT 1');
    $statement->execute([':report_id' => $report_id]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Fetch report rows for moderator review.
 *
 * @return array<int, array<string, mixed>>
 */
function admin_get_reports(PDO $pdo, string $status_filter): array
{
    $params = [];
    $whereClause = '';

    if ($status_filter !== 'all') {
        $whereClause = 'WHERE reports.status = :status';
        $params[':status'] = $status_filter;
    }

    $statement = $pdo->prepare(
        'SELECT
            reports.id,
            reports.reporter_id,
            reports.reported_user_id,
            reports.reason,
            reports.evidence_path,
            reports.status,
            reports.created_at,
            reporter_profiles.display_name AS reporter_display_name,
            reported_profiles.display_name AS reported_display_name
         FROM reports
         LEFT JOIN profiles AS reporter_profiles
            ON reporter_profiles.user_id = reports.reporter_id
         LEFT JOIN profiles AS reported_profiles
            ON reported_profiles.user_id = reports.reported_user_id
         ' . $whereClause . '
         ORDER BY
            CASE WHEN reports.status = "pending" THEN 0 ELSE 1 END,
            reports.created_at DESC,
            reports.id DESC'
    );
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// --- Frontend HTML will be added later ---
