<?php
/**
 * API: Simpan Catatan Kegiatan Harian NOC (Daily Activity)
 * IASS SBU ICT KNO
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$technicianName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'Teknisi';

$pdo = get_db_connection();

try {
    $shiftId = (int)($_POST['shift_id'] ?? 0);
    $shiftDate = trim($_POST['shift_date'] ?? date('Y-m-d'));
    $shiftType = trim($_POST['shift_type'] ?? 'pagi');
    $activityTime = trim($_POST['activity_time'] ?? date('H:i') . ' WIB');
    $category = trim($_POST['category'] ?? 'Monitoring');
    $location = trim($_POST['location'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Judul kegiatan wajib diisi.']);
        exit;
    }

    if (empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Uraian detail kegiatan wajib diisi.']);
        exit;
    }

    // 1. Resolve or Create Shift Session
    if ($shiftId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch();
        if (!$shift) {
            echo json_encode(['success' => false, 'message' => 'Sesi shift tidak ditemukan.']);
            exit;
        }
        // Non-admin can only write to their own active shift
        if (!has_role(['superadmin', 'admin']) && (int)$shift['user_id'] !== $userId) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat mencatat kegiatan di shift milik Anda sendiri.']);
            exit;
        }
        if ($shift['status'] === 'completed' && !has_role(['superadmin', 'admin'])) {
            echo json_encode(['success' => false, 'message' => 'Shift ini telah ditandai selesai dan terkunci.']);
            exit;
        }
    } else {
        // Find existing active shift for this user, date and shift_type
        $stmt = $pdo->prepare("
            SELECT * FROM daily_shifts 
            WHERE user_id = ? AND shift_date = ? AND shift_type = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$userId, $shiftDate, $shiftType]);
        $existingShift = $stmt->fetch();

        if ($existingShift) {
            $shiftId = (int)$existingShift['id'];
            if ($existingShift['status'] === 'completed' && !has_role(['superadmin', 'admin'])) {
                echo json_encode(['success' => false, 'message' => 'Shift ini sudah selesai. Silakan buat shift baru atau hubungi admin.']);
                exit;
            }
        } else {
            // Create new active shift session
            $stmt = $pdo->prepare("
                INSERT INTO daily_shifts (user_id, technician_name, shift_date, shift_type, status, notes, created_at)
                VALUES (?, ?, ?, ?, 'active', '', datetime('now', 'localtime'))
            ");
            // If MySQL, datetime function fallback
            try {
                $stmt->execute([$userId, $technicianName, $shiftDate, $shiftType]);
            } catch (Exception $ex) {
                $stmt = $pdo->prepare("
                    INSERT INTO daily_shifts (user_id, technician_name, shift_date, shift_type, status, notes, created_at)
                    VALUES (?, ?, ?, ?, 'active', '', NOW())
                ");
                $stmt->execute([$userId, $technicianName, $shiftDate, $shiftType]);
            }
            $shiftId = (int)$pdo->lastInsertId();
        }
    }

    // 2. Insert Activity Record
    $stmt = $pdo->prepare("
        INSERT INTO daily_activities (shift_id, user_id, activity_time, category, location, title, description, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
    ");
    try {
        $stmt->execute([$shiftId, $userId, $activityTime, $category, $location, $title, $description]);
    } catch (Exception $ex) {
        $stmt = $pdo->prepare("
            INSERT INTO daily_activities (shift_id, user_id, activity_time, category, location, title, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$shiftId, $userId, $activityTime, $category, $location, $title, $description]);
    }
    $activityId = (int)$pdo->lastInsertId();

    // 3. Process Photo Attachments
    $uploadDir = __DIR__ . '/../uploads/daily/' . $shiftId;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    @chmod($uploadDir, 0777);

    $savedPhotosCount = 0;

    // A. Handle Multipart File Uploads
    if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $count = count($_FILES['photos']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['photos']['tmp_name'][$i];
                $origName = $_FILES['photos']['name'][$i];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $newFilename = 'act_' . $activityId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $newFilename;
                    if (move_uploaded_file($tmpName, $destPath)) {
                        $relPath = 'uploads/daily/' . $shiftId . '/' . $newFilename;
                        $stmtPhoto = $pdo->prepare("
                            INSERT INTO daily_activity_photos (activity_id, photo_path, caption)
                            VALUES (?, ?, ?)
                        ");
                        $stmtPhoto->execute([$activityId, $relPath, $title]);
                        $savedPhotosCount++;
                    }
                }
            }
        }
    }

    // B. Handle Base64 Uploads (e.g. from in-app camera or canvas watermark)
    if (!empty($_POST['base64_photos'])) {
        $base64List = json_decode($_POST['base64_photos'], true);
        if (is_array($base64List)) {
            foreach ($base64List as $idx => $b64) {
                if (preg_match('/^data:image\/(\w+);base64,/', $b64, $type)) {
                    $data = substr($b64, strpos($b64, ',') + 1);
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $ext = strtolower($type[1]);
                        if ($ext === 'jpeg') $ext = 'jpg';
                        $newFilename = 'cam_' . $activityId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                        $destPath = $uploadDir . '/' . $newFilename;
                        if (file_put_contents($destPath, $data)) {
                            $relPath = 'uploads/daily/' . $shiftId . '/' . $newFilename;
                            $stmtPhoto = $pdo->prepare("
                                INSERT INTO daily_activity_photos (activity_id, photo_path, caption)
                                VALUES (?, ?, ?)
                            ");
                            $stmtPhoto->execute([$activityId, $relPath, $title]);
                            $savedPhotosCount++;
                        }
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'activity_id' => $activityId,
        'shift_id' => $shiftId,
        'photos_count' => $savedPhotosCount,
        'message' => 'Kegiatan harian berhasil dicatat ke dalam logbook shift.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
