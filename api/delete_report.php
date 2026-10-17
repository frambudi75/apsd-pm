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
            $pdo->prepare("DELETE FROM report_shares WHERE report_id = ?")->execute([$report_id]);

            // Delete master report record
            $pdo->prepare("DELETE FROM pm_reports WHERE id = ?")->execute([$report_id]);
        }

        $pdo->commit();

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
