<?php
/**
 * API Delete PM Report
 * PT Angkasa Pura Sarana Digital
 */
require_once __DIR__ . '/../config/database.php';
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

        header("Location: ../index.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("Gagal menghapus laporan: " . htmlspecialchars($e->getMessage()));
    }
}

header("Location: ../index.php");
exit;
