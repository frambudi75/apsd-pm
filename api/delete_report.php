<?php
/**
 * API Delete PM Report (Single & Batch Delete)
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!has_role(['superadmin', 'admin'])) {
    die("Akses ditolak: Hanya Admin dan Superadmin yang dapat menghapus laporan.");
}

$pdo = get_db_connection();

// Support single delete via GET/POST id, or batch delete via POST ids[]
$ids = [];
if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
    foreach ($_POST['ids'] as $rawId) {
        $val = intval($rawId);
        if ($val > 0) $ids[] = $val;
    }
} elseif (!empty($_GET['id'])) {
    $val = intval($_GET['id']);
    if ($val > 0) $ids[] = $val;
} elseif (!empty($_POST['id'])) {
    $val = intval($_POST['id']);
    if ($val > 0) $ids[] = $val;
}

if (!empty($ids)) {
    try {
        // Pre-fetch report metadata for descriptive audit logging before deletion
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtMeta = $pdo->prepare("
            SELECT r.id, r.report_type, r.report_date, s.site_name 
            FROM pm_reports r 
            LEFT JOIN sites s ON r.site_id = s.id 
            WHERE r.id IN ($placeholders)
        ");
        $stmtMeta->execute($ids);
        $deletedReportsMeta = $stmtMeta->fetchAll();

        $pdo->beginTransaction();

        foreach ($ids as $report_id) {
            // Remove photo directory if exists
            $uploadDir = __DIR__ . "/../uploads/reports/{$report_id}";
            if (is_dir($uploadDir)) {
                $files = glob($uploadDir . '/*');
                if ($files) {
                    foreach ($files as $file) {
                        if (is_file($file)) @unlink($file);
                    }
                }
                @rmdir($uploadDir);
            }

            // Cleanup child tables explicitly
            $pdo->prepare("DELETE FROM pm_indoor_details WHERE report_id = ?")->execute([$report_id]);
            $pdo->prepare("DELETE FROM pm_outdoor_details WHERE report_id = ?")->execute([$report_id]);
            $pdo->prepare("DELETE FROM pm_photos WHERE report_id = ?")->execute([$report_id]);

            // Delete master report record
            $pdo->prepare("DELETE FROM pm_reports WHERE id = ?")->execute([$report_id]);
        }

        $pdo->commit();

        // Record audit trail event
        if (function_exists('log_audit_event')) {
            $summaryParts = [];
            foreach ($deletedReportsMeta as $rm) {
                $siteLabel = $rm['site_name'] ?: ('Site #' . $rm['id']);
                $dateLabel = !empty($rm['report_date']) ? date('d/m/Y', strtotime($rm['report_date'])) : '-';
                $summaryParts[] = strtoupper($rm['report_type']) . ' ' . $siteLabel . ' (' . $dateLabel . ')';
            }
            $logDetail = 'Hapus ' . count($ids) . ' laporan PM: ' . implode('; ', array_slice($summaryParts, 0, 5));
            if (count($summaryParts) > 5) {
                $logDetail .= ' dan ' . (count($summaryParts) - 5) . ' laporan lainnya.';
            }
            log_audit_event('REPORT', 'REPORT_DELETE', $logDetail, 'pm_report', count($ids) === 1 ? $ids[0] : null);
        }

        // Invalidate Redis cache immediately so changes appear instantly without delay
        require_once __DIR__ . '/../config/redis.php';
        AppRedis::flushPrefix('dashboard:');
        AppRedis::flushPrefix('reports:');
        AppRedis::del('reports:all');
        AppRedis::del('reports:all_v2');

        $countDeleted = count($ids);
        $returnDest = ($_GET['return'] ?? $_POST['return'] ?? '') === 'reports' 
            ? "../reports?msg=deleted&count={$countDeleted}" 
            : "../?msg=deleted&count={$countDeleted}";
        header("Location: " . $returnDest);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("Gagal menghapus laporan: " . htmlspecialchars($e->getMessage()));
    }
}

$returnDest = ($_GET['return'] ?? $_POST['return'] ?? '') === 'reports' ? '../reports' : '../';
header("Location: " . $returnDest);
exit;
