<?php
declare(strict_types=1);
require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/admin_access.php';
require_once __DIR__ . '/../php/admin_evidence.php';
require_once __DIR__ . '/../php/logger.php';
require_login();
bushisa_require_moderator($pdo);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET'); http_response_code(405); exit('Use the report details page to open an attachment.');
}
$id = isset($_GET['report']) && is_string($_GET['report']) ? filter_var($_GET['report'],FILTER_VALIDATE_INT) : false;
$evidence = null;
try {
    if (is_int($id) && $id > 0) {
        $query = $pdo->prepare('SELECT evidence_path FROM reports WHERE id = ?');
        $query->execute([$id]);
        $path = $query->fetchColumn();
        if (is_string($path)) $evidence = admin_evidence_open($path);
    }
    if (!$evidence) { http_response_code(404); exit('This attachment is unavailable or cannot be served safely.'); }
    log_action($pdo,(int)$_SESSION['user_id'],'view_report_evidence:' . $id);
    header('Content-Type: ' . $evidence['mime']);
    header('Content-Length: ' . $evidence['size']);
    header('Content-Disposition: ' . ($evidence['download'] ? 'attachment' : 'inline') . '; filename="report-' . $id . '-evidence.' . $evidence['extension'] . '"');
    session_write_close();
    fpassthru($evidence['stream']);
    fclose($evidence['stream']);
} catch (Throwable $exception) {
    if ($evidence && is_resource($evidence['stream'])) fclose($evidence['stream']);
    error_log('Report evidence unavailable: ' . $exception->getCode());
    http_response_code(503);
    exit('The attachment is temporarily unavailable. Please try again later.');
}
