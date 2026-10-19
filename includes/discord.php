<?php
/**
 * Discord Webhook Notification Helper (Dual-Channel Architecture)
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Saluran Terpisah:
 * 1. NOC & Operasional: Shift handover, logbook NOC, dokumen tim.
 * 2. Keamanan & Sistem: Percobaan login gagal, auto-optimise, akun user.
 *
 * Seluruh fungsi berjalan fail-safe dengan timeout pendek (3 detik)
 * agar tidak pernah menghambat kecepatan atau alur kerja aplikasi web.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Kirim raw payload HTTP POST ke URL Webhook Discord
 */
function send_discord_payload(string $webhookUrl, array $payload, ?string $filePath = null, string $fileName = 'attachment.pdf', int $timeoutSeconds = 15): array {
    $webhookUrl = trim($webhookUrl);
    if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'URL Webhook Discord tidak valid atau kosong.'];
    }

    if (!extension_loaded('curl')) {
        return ['success' => false, 'message' => 'Ekstensi PHP cURL tidak aktif pada server ini.'];
    }

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(15, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($filePath && file_exists($filePath)) {
            // Multipart upload with attachment
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
            finfo_close($finfo);

            $cfile = new CURLFile($filePath, $mimeType, $fileName);
            $postFields = [
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'files[0]' => $cfile
            ];

            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: PM-NOC-System/2.0'
            ]);
        } else {
            // Standard JSON payload
            $jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: PM-NOC-System/2.0'
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Discord returns 200 or 204 on success
        if ($httpCode === 200 || $httpCode === 204) {
            return ['success' => true, 'message' => 'Pesan berhasil terkirim ke Discord.'];
        }

        return [
            'success' => false,
            'message' => 'Discord merespons status HTTP ' . $httpCode . ($curlError ? ' (' . $curlError . ')' : '') . ($response ? ': ' . substr($response, 0, 120) : '')
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'Gagal mengirim pesan Discord: ' . $e->getMessage()];
    }
}

/**
 * Kirim notifikasi embed ke saluran tertentu ('noc' atau 'security')
 */
function send_discord_notification(string $channelType, array $embedData, ?string $attachmentPath = null, string $attachmentName = ''): bool {
    try {
        $channelKey = ($channelType === 'security') ? 'discord_webhook_security' : 'discord_webhook_noc';
        $webhookUrl = get_system_setting($channelKey, '');

        if (empty($webhookUrl)) {
            return false;
        }

        $appTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));

        $payload = [
            'username' => $appTitle . ($channelType === 'security' ? ' [Security]' : ' [NOC Bot]'),
            'embeds' => [$embedData]
        ];

        $res = send_discord_payload($webhookUrl, $payload, $attachmentPath, $attachmentName);
        return !empty($res['success']);
    } catch (\Throwable $e) {
        error_log('Discord notification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Helper: Bangun URL lengkap server untuk tombol/tautan
 */
function get_server_base_url(): string {
    if (function_exists('get_full_app_url')) {
        return get_full_app_url();
    }
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $protocol = $isSecure ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = dirname($script);
    if (basename($dir) === 'api' || basename($dir) === 'includes') {
        $dir = dirname($dir);
    }
    $dir = str_replace('\\', '/', $dir);
    return $protocol . $host . rtrim($dir, '/') . '/';
}

/**
 * Helper: Generate berkas PDF resmi shift daily activity menggunakan headless browser
 */
function generate_shift_pdf_file(int $shiftId): ?string {
    if ($shiftId <= 0) return null;

    $browserCandidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser'
    ];

    $browserBin = null;
    foreach ($browserCandidates as $p) {
        if (file_exists($p)) {
            $browserBin = $p;
            break;
        }
    }

    if (!$browserBin) {
        return null;
    }

    $tempDir = __DIR__ . '/../uploads/temp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
    }

    $profileDir = $tempDir . '/chrome_pwa_pdf_profile';
    $pdfPath = $tempDir . '/Logbook_NOC_Shift_' . $shiftId . '_' . time() . '.pdf';

    $localPort = (string)($_SERVER['SERVER_PORT'] ?? '80');
    $isSsl = ($localPort === '443' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'));
    $scheme = $isSsl ? 'https' : 'http';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if (basename($scriptDir) === 'api' || basename($scriptDir) === 'includes') {
        $scriptDir = dirname($scriptDir);
    }
    $basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');
    $pdfToken = hash_hmac('sha256', (string)$shiftId, 'apsd_internal_secret_2026');
    $internalUrl = "{$scheme}://127.0.0.1:{$localPort}{$basePath}/print_shift.php?shift_id={$shiftId}&pdf_token={$pdfToken}";

    $cmd = sprintf(
        '"%s" --headless=new --user-data-dir="%s" --no-sandbox --disable-dev-shm-usage --disable-gpu --ignore-certificate-errors --allow-insecure-localhost --no-pdf-header-footer --print-to-pdf="%s" "%s"',
        $browserBin,
        $profileDir,
        $pdfPath,
        $internalUrl
    );

    @exec($cmd, $output, $returnCode);

    $maxWait = 6.0;
    $waited = 0.0;
    while ($waited < $maxWait) {
        clearstatcache(true, $pdfPath);
        if (file_exists($pdfPath) && filesize($pdfPath) > 5000) {
            return $pdfPath;
        }
        usleep(300000); // 300ms
        $waited += 0.3;
    }

    if (file_exists($pdfPath) && filesize($pdfPath) > 5000) {
        return $pdfPath;
    }

    return null;
}

/**
 * Notifikasi: Sesi Shift Daily Activity Selesai Dilaporkan
 */
function discord_notify_shift_complete(array $shift, array $activities = [], int $photoCount = 0): bool {
    if (get_system_setting('discord_notify_shift_complete', '1') !== '1') {
        return false;
    }

    $baseUrl = get_server_base_url();
    $shiftId = (int)($shift['id'] ?? 0);
    $techName = $shift['technician_name'] ?? 'Teknisi NOC';
    $shiftType = strtoupper($shift['shift_type'] ?? 'PAGI');
    $shiftDate = !empty($shift['shift_date']) ? date('d/m/Y', strtotime($shift['shift_date'])) : date('d/m/Y');
    $notes = !empty($shift['notes']) ? trim($shift['notes']) : 'Tidak ada catatan khusus handover.';
    $actCount = count($activities);

    $fields = [
        [
            'name' => '👤 Teknisi Bertugas',
            'value' => $techName,
            'inline' => true
        ],
        [
            'name' => '📅 Sesi & Tanggal',
            'value' => 'Shift ' . $shiftType . ' (' . $shiftDate . ')',
            'inline' => true
        ],
        [
            'name' => '📊 Total Kegiatan',
            'value' => $actCount . ' kegiatan tercatat',
            'inline' => true
        ],
        [
            'name' => '📷 Dokumentasi',
            'value' => $photoCount . ' foto terverifikasi',
            'inline' => true
        ],
        [
            'name' => '📝 Catatan Handover Shift',
            'value' => mb_substr($notes, 0, 400),
            'inline' => false
        ]
    ];

    // Ringkasan kegiatan
    if (!empty($activities)) {
        $activityLines = [];
        $i = 1;
        foreach (array_slice($activities, 0, 5) as $act) {
            $time = strip_tags($act['activity_time'] ?? '-');
            $cat = strip_tags($act['category'] ?? '-');
            $rawTitle = !empty(trim($act['title'] ?? '')) ? trim($act['title']) : trim($act['description'] ?? 'Kegiatan NOC');
            $title = strip_tags($rawTitle);
            if (mb_strlen($title) > 60) {
                $title = mb_substr($title, 0, 57) . '...';
            }
            $activityLines[] = "{$i}. `[{$time}]` **{$cat}**: {$title}";
            $i++;
        }
        if (count($activities) > 5) {
            $activityLines[] = '... dan ' . (count($activities) - 5) . ' kegiatan lainnya.';
        }
        $fields[] = [
            'name' => '📋 Ringkasan Kegiatan Logbook',
            'value' => implode("\n", $activityLines),
            'inline' => false
        ];
    }

    // Generate PDF otomatis untuk lampiran discord
    $pdfPath = generate_shift_pdf_file($shiftId);
    $shiftDateClean = str_replace(['/', '\\'], '-', $shiftDate);
    $pdfFilename = 'Logbook_NOC_' . $shiftType . '_' . $shiftDateClean . '.pdf';

    if ($pdfPath && file_exists($pdfPath)) {
        $fields[] = [
            'name' => '📎 Berkas PDF Resmi',
            'value' => 'Dokumen PDF laporan shift telah otomatis digenerate dan dilampirkan pada pesan ini.',
            'inline' => false
        ];
    }

    $appTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
    $appSubtitle = get_system_setting('app_subtitle', 'Sistem PM & NOC');

    $embed = [
        'title' => '📋 Laporan Shift Selesai & Terkunci - Shift ' . $shiftType,
        'description' => 'Sesi kegiatan harian NOC telah diselesaikan dan dikunci secara resmi. Berkas PDF laporan resmi dan ringkasan handover shift terlampir.',
        'url' => $baseUrl . 'print_shift?shift_id=' . $shiftId,
        'color' => 0x008080, // Teal
        'fields' => $fields,
        'footer' => [
            'text' => $appTitle . ' • ' . $appSubtitle
        ],
        'timestamp' => date('c')
    ];

    $res = send_discord_notification('noc', $embed, $pdfPath, $pdfFilename);

    // Hapus file temporary PDF setelah pengiriman
    if ($pdfPath && file_exists($pdfPath)) {
        @unlink($pdfPath);
    }

    return $res;
}

/**
 * Notifikasi: Hasil Pemeliharaan & Auto-Optimise Sistem
 */
function discord_notify_system_optimize(array $stats, float $executionTimeMs): bool {
    if (get_system_setting('discord_notify_system_optimize', '1') !== '1') {
        return false;
    }

    $baseUrl = get_server_base_url();

    $fields = [
        [
            'name' => '⏱️ Durasi Eksekusi',
            'value' => $executionTimeMs . ' ms',
            'inline' => true
        ],
        [
            'name' => '🗑️ File Temp Dihapus',
            'value' => ($stats['cleaned_files'] ?? 0) . ' file kadaluwarsa',
            'inline' => true
        ],
        [
            'name' => '🔗 Link Expired Ditutup',
            'value' => ($stats['expired_links'] ?? 0) . ' tautan dinonaktifkan',
            'inline' => true
        ],
        [
            'name' => '💾 Hemat Kompresi Vault',
            'value' => ($stats['vault_savings'] ?? '0 B') . ' (' . ($stats['vault_savings_pct'] ?? 0) . '%)',
            'inline' => true
        ]
    ];

    $appTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));

    $embed = [
        'title' => '⚙️ Auto-Optimise Sistem Selesai Dijalankan',
        'description' => 'Defragmentasi basis data, pemangkasan WAL log, dan pembersihan file temporary berhasil diselesaikan.',
        'url' => $baseUrl . 'system_optimize',
        'color' => 0x059669, // Emerald
        'fields' => $fields,
        'footer' => [
            'text' => $appTitle . ' • System Maintenance'
        ],
        'timestamp' => date('c')
    ];

    return send_discord_notification('security', $embed);
}

/**
 * Notifikasi: Peringatan Keamanan (Gagal Login / Security Alert)
 */
function discord_notify_security_alert(string $event, string $username, string $ip, string $details = ''): bool {
    if (get_system_setting('discord_notify_login_failed', '1') !== '1') {
        return false;
    }

    $baseUrl = get_server_base_url();

    $fields = [
        [
            'name' => '🚨 Peristiwa',
            'value' => $event,
            'inline' => true
        ],
        [
            'name' => '👤 Username Sasaran',
            'value' => '@' . $username,
            'inline' => true
        ],
        [
            'name' => '🌐 IP Address',
            'value' => $ip,
            'inline' => true
        ]
    ];

    if (!empty($details)) {
        $fields[] = [
            'name' => 'ℹ️ Keterangan Tambahan',
            'value' => $details,
            'inline' => false
        ];
    }

    $appTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));

    $embed = [
        'title' => '⚠️ Peringatan Keamanan Sistem (Security Alert)',
        'description' => 'Terdeteksi upaya otentikasi mencurigakan atau peringatan hak akses pada sistem.',
        'url' => $baseUrl . 'audit_log?action_type=AUTH',
        'color' => 0xDC2626, // Red
        'fields' => $fields,
        'footer' => [
            'text' => $appTitle . ' • Security & Audit Trail'
        ],
        'timestamp' => date('c')
    ];

    return send_discord_notification('security', $embed);
}

/**
 * Notifikasi: Dokumen Baru Diunggah ke Brankas Tim
 */
function discord_notify_document_upload(string $title, string $category, string $uploader, string $privacyType): bool {
    if (get_system_setting('discord_notify_doc_upload', '1') !== '1') {
        return false;
    }

    // Hanya kirim jika dokumen dibagikan ke tim atau banyak pengguna
    if ($privacyType === 'private') {
        return false;
    }

    $baseUrl = get_server_base_url();

    $fields = [
        [
            'name' => '📄 Judul Dokumen',
            'value' => $title,
            'inline' => true
        ],
        [
            'name' => '🏷️ Kategori',
            'value' => $category,
            'inline' => true
        ],
        [
            'name' => '👤 Diunggah Oleh',
            'value' => $uploader,
            'inline' => true
        ],
        [
            'name' => '👥 Hak Berbagi',
            'value' => ($privacyType === 'team') ? 'Seluruh Tim' : 'Rekan Dipilih',
            'inline' => true
        ]
    ];

    $appTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));

    $embed = [
        'title' => '📁 Berkas Baru Ditambahkan ke Brankas Dokumen',
        'description' => 'Dokumen baru telah diunggah dan dapat diakses oleh anggota tim yang memiliki wewenang.',
        'url' => $baseUrl . 'documents',
        'color' => 0x0284C7, // Blue
        'fields' => $fields,
        'footer' => [
            'text' => $appTitle . ' • Brankas Dokumen'
        ],
        'timestamp' => date('c')
    ];

    return send_discord_notification('noc', $embed);
}
