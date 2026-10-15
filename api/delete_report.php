<?php
/**
 * API Delete PM Report
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!has_role(['superadmin', 'admin'])) {
    die("Akses ditolak: Hanya Admin dan Superadmin yang dapat menghapus laporan.");
}

$pdo = get_db_connection();

$report_id = intval($_GET['id'] ?? 0);
if ($report_id > 0) {
    try {
        $pdo->beginTransaction();

        // Remove photo directory if exists
        $uploadDir = __DIR__ . "/../uploads/reports/{$report_id}";
        if (is_dir($uploadDir)) {
            $files = glob($uploadDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) @unlink($file);
            }
            @rmdir($uploadDir);
        }

        $stmt = $pdo->prepare("DELETE FROM pm_reports WHERE id = ?");
        $stmt->execute([$report_id]);

        $pdo->commit();

        // Invalidate Redis cache
        require_once __DIR__ . '/../config/redis.php';
        AppRedis::flushPrefix('dashboard:');
        AppRedis::del('reports:all');

        $returnDest = ($_GET['return'] ?? '') === 'reports' ? '../reports?msg=deleted' : '../?msg=deleted';
        header("Location: " . $returnDest);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("Gagal menghapus laporan: " . htmlspecialchars($e->getMessage()));
    }
}

$returnDest = ($_GET['return'] ?? '') === 'reports' ? '../reports' : '../';
header("Location: " . $returnDest);
exit;
