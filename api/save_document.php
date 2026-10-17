<?php
/**
 * API: Simpan dan Kompres Dokumen Pribadi & Berbagi (Document Vault)
 * IASS SBU ICT KNO
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir. Silakan login kembali.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$pdo = get_db_connection();

require_once __DIR__ . '/../includes/document_vault.php';

try {
    $docId = (int)($_POST['document_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'Lainnya');
    $description = trim($_POST['description'] ?? '');
    $privacyType = trim($_POST['privacy_type'] ?? 'private');
    $expiryDate = trim($_POST['expiry_date'] ?? '');
    $expiryDateVal = !empty($expiryDate) ? $expiryDate : null;

    // Allowed privacy types
    if (!in_array($privacyType, ['private', 'shared_users', 'team'], true)) {
        $privacyType = 'private';
    }

    // Share users array
    $rawShareUsers = $_POST['share_user_ids'] ?? [];
    if (!is_array($rawShareUsers)) {
        $rawShareUsers = !empty($rawShareUsers) ? explode(',', (string)$rawShareUsers) : [];
    }
    $shareUserIds = array_filter(array_map('intval', $rawShareUsers), function($id) use ($userId) {
        return $id > 0 && $id !== $userId;
    });

    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Judul dokumen wajib diisi.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/documents';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    @chmod($uploadDir, 0777);

    // Editing existing document
    if ($docId > 0) {
        $stmtCheck = $pdo->prepare("SELECT * FROM user_documents WHERE id = ?");
        $stmtCheck->execute([$docId]);
        $existingDoc = $stmtCheck->fetch();

        if (!$existingDoc) {
            echo json_encode(['success' => false, 'message' => 'Dokumen tidak ditemukan.']);
            exit;
        }

        // Strict privacy rule: Only owner can edit metadata or file
        if ((int)$existingDoc['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: Hanya pemilik dokumen yang dapat mengubah dokumen ini.']);
            exit;
        }

        $storedFilename = $existingDoc['stored_filename'];
        $originalFilename = $existingDoc['original_filename'];
        $fileSizeRaw = (int)$existingDoc['file_size_raw'];
        $fileSizeCompressed = (int)$existingDoc['file_size_compressed'];
        $mimeType = $existingDoc['mime_type'];
        $fileExt = $existingDoc['file_ext'];
        $isCompressed = (int)$existingDoc['is_compressed'];

        // If replacement file uploaded
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['document_file'];
            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            $disallowedExts = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'pl', 'cgi', 'py', 'js', 'vbs', 'msi'];
            if (in_array($ext, $disallowedExts, true)) {
                echo json_encode(['success' => false, 'message' => 'Tipe berkas tidak diizinkan demi alasan keamanan.']);
                exit;
            }

            if ($file['size'] > 64 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran berkas melebihi batas maksimum 64 MB.']);
                exit;
            }

            $proc = process_and_compress_document_file($file['tmp_name'], $ext, (int)$file['size']);
            if (isset($proc['error'])) {
                echo json_encode(['success' => false, 'message' => $proc['error']]);
                exit;
            }

            $finalData = $proc['final_data'];
            $rawSize = $proc['raw_size'];
            $compSize = $proc['compressed_size'];
            $isComp = $proc['is_compressed'];
            $mimeType = $proc['mime_type'];

            // Remove old physical file if exists
            $oldPath = $uploadDir . '/' . $existingDoc['stored_filename'];
            if (!empty($existingDoc['stored_filename']) && file_exists($oldPath)) {
                @unlink($oldPath);
            }

            $newStoredFilename = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.bin';
            $savePath = $uploadDir . '/' . $newStoredFilename;
            if (file_put_contents($savePath, $finalData) === false) {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan berkas terkompresi di server.']);
                exit;
            }
            @chmod($savePath, 0666);

            $storedFilename = $newStoredFilename;
            $originalFilename = $origName;
            $fileSizeRaw = $rawSize;
            $fileSizeCompressed = $compSize;
            $fileExt = $ext;
            $isCompressed = $isComp;
        }

        // Update database record
        $stmtUpdate = $pdo->prepare("
            UPDATE user_documents SET
                title = ?,
                category = ?,
                description = ?,
                original_filename = ?,
                stored_filename = ?,
                file_size_raw = ?,
                file_size_compressed = ?,
                mime_type = ?,
                file_ext = ?,
                is_compressed = ?,
                privacy_type = ?,
                expiry_date = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmtUpdate->execute([
            $title, $category, $description,
            $originalFilename, $storedFilename,
            $fileSizeRaw, $fileSizeCompressed,
            $mimeType, $fileExt, $isCompressed,
            $privacyType, $expiryDateVal,
            $docId
        ]);

        // Sync sharing permissions
        $stmtDelShares = $pdo->prepare("DELETE FROM user_document_shares WHERE document_id = ?");
        $stmtDelShares->execute([$docId]);

        if ($privacyType === 'shared_users' && !empty($shareUserIds)) {
            $stmtInsertShare = $pdo->prepare("
                INSERT INTO user_document_shares (document_id, shared_with_user_id, shared_by_user_id, created_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            foreach ($shareUserIds as $targetUserId) {
                $stmtInsertShare->execute([$docId, $targetUserId, $userId]);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Dokumen berhasil diperbarui dengan aman.',
            'document_id' => $docId
        ]);
        exit;
    }

    // Creating new document (file is required)
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = 'Silakan pilih berkas yang ingin diunggah.';
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $errMsg = 'Ukuran berkas melebihi kapasitas server (maksimal 64 MB).';
        }
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit;
    }

    $file = $_FILES['document_file'];
    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    $disallowedExts = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'pl', 'cgi', 'py', 'js', 'vbs', 'msi'];
    if (in_array($ext, $disallowedExts, true)) {
        echo json_encode(['success' => false, 'message' => 'Tipe berkas tidak diizinkan demi alasan keamanan.']);
        exit;
    }

    if ($file['size'] > 64 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Ukuran berkas melebihi batas maksimum 64 MB.']);
        exit;
    }

    $proc = process_and_compress_document_file($file['tmp_name'], $ext, (int)$file['size']);
    if (isset($proc['error'])) {
        echo json_encode(['success' => false, 'message' => $proc['error']]);
        exit;
    }

    $finalData = $proc['final_data'];
    $rawSize = $proc['raw_size'];
    $compressedSize = $proc['compressed_size'];
    $isCompressed = $proc['is_compressed'];
    $mimeType = $proc['mime_type'];

    $storedFilename = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.bin';
    $savePath = $uploadDir . '/' . $storedFilename;
    if (file_put_contents($savePath, $finalData) === false) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan berkas di server. Periksa hak akses folder.']);
        exit;
    }
    @chmod($savePath, 0666);

    $stmtInsert = $pdo->prepare("
        INSERT INTO user_documents (
            user_id, title, category, description,
            original_filename, stored_filename,
            file_size_raw, file_size_compressed, mime_type, file_ext,
            is_compressed, privacy_type, expiry_date,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmtInsert->execute([
        $userId, $title, $category, $description,
        $origName, $storedFilename,
        $rawSize, $compressedSize, $mimeType, $ext,
        $isCompressed, $privacyType, $expiryDateVal
    ]);

    $newDocId = (int)$pdo->lastInsertId();

    // Insert shares if shared_users selected
    if ($privacyType === 'shared_users' && !empty($shareUserIds)) {
        $stmtInsertShare = $pdo->prepare("
            INSERT INTO user_document_shares (document_id, shared_with_user_id, shared_by_user_id, created_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        foreach ($shareUserIds as $targetUserId) {
            $stmtInsertShare->execute([$newDocId, $targetUserId, $userId]);
        }
    }

    $savedPct = ($rawSize > 0 && $compressedSize < $rawSize) 
        ? round((1 - ($compressedSize / $rawSize)) * 100, 1) 
        : 0;

    echo json_encode([
        'success' => true,
        'message' => 'Dokumen berhasil disimpan dan terkompresi (' . $savedPct . '% hemat ruang).',
        'document_id' => $newDocId,
        'saved_percent' => $savedPct
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
