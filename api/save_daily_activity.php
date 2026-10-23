<?php
/**
 * API: Simpan Catatan Kegiatan Harian NOC (Daily Activity)
 * IASS SBU ICT KNO
 */
// Prevent raw HTML error output from corrupting JSON responses
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');

function send_json_response(array $data, int $statusCode = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Check if POST body exceeded PHP post_max_size
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $postLimit = ini_get('post_max_size') ?: 'server limit';
    send_json_response([
        'success' => false,
        'message' => "Ukuran total upload foto melebihi batas server ({$postLimit}). Foto akan otomatis dikompresi di browser, silakan coba kembali."
    ], 413);
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!is_logged_in()) {
    send_json_response(['success' => false, 'message' => 'Sesi login telah berakhir.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(['success' => false, 'message' => 'Metode request tidak diizinkan.'], 405);
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
        send_json_response(['success' => false, 'message' => 'Judul kegiatan wajib diisi.']);
    }

    if (empty($description)) {
        send_json_response(['success' => false, 'message' => 'Uraian detail kegiatan wajib diisi.']);
    }

    // 1. Resolve or Create Shift Session
    if ($shiftId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM daily_shifts WHERE id = ?");
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch();
        if (!$shift) {
            send_json_response(['success' => false, 'message' => 'Sesi shift tidak ditemukan.']);
        }
        // Non-admin can only write to their own active shift
        if (!has_role(['superadmin', 'admin']) && (int)$shift['user_id'] !== $userId) {
            send_json_response(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat mencatat kegiatan di shift milik Anda sendiri.']);
        }
        if ($shift['status'] === 'completed' && !has_role(['superadmin', 'admin'])) {
            send_json_response(['success' => false, 'message' => 'Shift ini telah ditandai selesai dan terkunci.']);
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
                send_json_response(['success' => false, 'message' => 'Shift ini sudah selesai. Silakan buat shift baru atau hubungi admin.']);
            }
        } else {
            send_json_response([
                'success' => false,
                'message' => 'Sesi shift belum dibuka. Silakan pilih dan mulai sesi shift terlebih dahulu sebelum mencatat kegiatan.'
            ]);
        }
    }

    // 2. Insert Activity Record
    $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("
            INSERT INTO daily_activities (shift_id, user_id, activity_time, category, location, title, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO daily_activities (shift_id, user_id, activity_time, category, location, title, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
    }
    $stmt->execute([$shiftId, $userId, $activityTime, $category, $location, $title, $description]);
    $activityId = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/../includes/image_compressor.php';

    // 3. Process Photo Attachments
    $uploadDir = __DIR__ . '/../uploads/daily/' . $shiftId;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    @chmod($uploadDir, 0777);

    $savedPhotosCount = 0;

    // A. Structured Photo Items: activity_photos (array of slots with caption 1 & caption 2)
    $structuredPhotos = $_POST['activity_photos'] ?? [];
    if (is_array($structuredPhotos) && !empty($structuredPhotos)) {
        foreach ($structuredPhotos as $idx => $meta) {
            $cap1 = trim($meta['caption'] ?? '');
            if (empty($cap1)) {
                $cap1 = $title; // Fallback to activity title if empty
            }
            $cap2 = trim($meta['caption_2'] ?? '');

            $savedPath = null;

            // 1. Check if base64 provided (from camera modal / live preview)
            $b64 = $meta['base64'] ?? ($_POST['activity_photo_base64'][$idx] ?? '');
            if (!empty($b64) && preg_match('/^data:image\/(\w+);base64,/', $b64, $type)) {
                $data = substr($b64, strpos($b64, ',') + 1);
                $data = base64_decode($data);
                if ($data !== false) {
                    $ext = strtolower($type[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    $newFilename = 'act_' . $activityId . '_slot_' . $idx . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $newFilename;
                    if (@file_put_contents($destPath, $data)) {
                        if (function_exists('compress_and_save_image')) {
                            try {
                                @compress_and_save_image($destPath, $destPath, 1600, 82);
                            } catch (\Throwable $t) {}
                        }
                        $savedPath = 'uploads/daily/' . $shiftId . '/' . $newFilename;
                    }
                }
            }

            // 2. Check if file uploaded via multipart
            if (!$savedPath && isset($_FILES['activity_photo_files']['name'][$idx]) && $_FILES['activity_photo_files']['error'][$idx] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['activity_photo_files']['tmp_name'][$idx];
                $origName = $_FILES['activity_photo_files']['name'][$idx];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $newFilename = 'act_' . $activityId . '_slot_' . $idx . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $newFilename;
                    if (@move_uploaded_file($tmpName, $destPath)) {
                        if (function_exists('compress_and_save_image')) {
                            try {
                                @compress_and_save_image($destPath, $destPath, 1600, 82);
                            } catch (\Throwable $t) {}
                        }
                        $savedPath = 'uploads/daily/' . $shiftId . '/' . $newFilename;
                    }
                }
            }

            // Insert into daily_activity_photos if photo saved
            if ($savedPath) {
                $stmtPhoto = $pdo->prepare("
                    INSERT INTO daily_activity_photos (activity_id, photo_path, caption, caption_2)
                    VALUES (?, ?, ?, ?)
                ");
                $stmtPhoto->execute([$activityId, $savedPath, $cap1, $cap2]);
                $savedPhotosCount++;
            }
        }
    }

    // B. Legacy fallback: Handle Multipart File Uploads (photos[])
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
                    if (@move_uploaded_file($tmpName, $destPath)) {
                        if (function_exists('compress_and_save_image')) {
                            try {
                                @compress_and_save_image($destPath, $destPath, 1600, 82);
                            } catch (\Throwable $t) {}
                        }
                        $relPath = 'uploads/daily/' . $shiftId . '/' . $newFilename;
                        $stmtPhoto = $pdo->prepare("
                            INSERT INTO daily_activity_photos (activity_id, photo_path, caption, caption_2)
                            VALUES (?, ?, ?, NULL)
                        ");
                        $stmtPhoto->execute([$activityId, $relPath, $title]);
                        $savedPhotosCount++;
                    }
                }
            }
        }
    }

    // C. Legacy fallback: Handle Base64 Uploads (base64_photos)
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
                        if (@file_put_contents($destPath, $data)) {
                            if (function_exists('compress_and_save_image')) {
                                try {
                                @compress_and_save_image($destPath, $destPath, 1600, 82);
                            } catch (\Throwable $t) {}
                            }
                            $relPath = 'uploads/daily/' . $shiftId . '/' . $newFilename;
                            $stmtPhoto = $pdo->prepare("
                                INSERT INTO daily_activity_photos (activity_id, photo_path, caption, caption_2)
                                VALUES (?, ?, ?, NULL)
                            ");
                            $stmtPhoto->execute([$activityId, $relPath, $title]);
                            $savedPhotosCount++;
                        }
                    }
                }
            }
        }
    }

    send_json_response([
        'success' => true,
        'activity_id' => $activityId,
        'shift_id' => $shiftId,
        'photos_count' => $savedPhotosCount,
        'message' => 'Kegiatan harian berhasil dicatat ke dalam logbook shift.'
    ]);

} catch (\Throwable $e) {
    error_log("save_daily_activity error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    send_json_response([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat menyimpan kegiatan: ' . $e->getMessage()
    ], 500);
}

