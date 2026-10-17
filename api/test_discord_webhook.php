<?php
/**
 * API: Uji Coba Pengiriman Notifikasi Discord Webhook
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Khusus Superadmin: Menguji koneksi dan format pesan ke webhook Discord
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/discord.php';

// Strict RBAC: Hanya Superadmin yang boleh menguji webhook
if (!is_logged_in() || !has_role('superadmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Hanya Superadmin yang dapat melakukan uji coba webhook.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

$webhookUrl = trim($_POST['webhook_url'] ?? '');
$channelType = trim($_POST['channel_type'] ?? 'noc');

if (empty($webhookUrl)) {
    echo json_encode(['success' => false, 'message' => 'Silakan masukkan URL Webhook Discord terlebih dahulu.']);
    exit;
}

if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'message' => 'Format URL Webhook Discord tidak valid.']);
    exit;
}

$appTitle = get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO'));
$appSubtitle = get_system_setting('app_subtitle', 'Sistem PM & NOC');
$currentUser = current_user();
$shouldSave = isset($_POST['save']) ? ($_POST['save'] === '1' || $_POST['save'] === 'true') : true;

if ($channelType === 'security') {
    $payload = [
        'username' => $appTitle . ' [Security Bot]',
        'embeds' => [
            [
                'title' => '🛡️ Uji Coba Koneksi Saluran Keamanan & Audit Sistem',
                'description' => 'Koneksi Discord Webhook untuk saluran keamanan dan audit sistem berhasil terhubung dengan sempurna!',
                'color' => 0x0284C7, // Blue
                'fields' => [
                    [
                        'name' => '👤 Penguji',
                        'value' => htmlspecialchars($currentUser['full_name'] ?? 'Superadmin') . ' (@' . htmlspecialchars($currentUser['username'] ?? 'superadmin') . ')',
                        'inline' => true
                    ],
                    [
                        'name' => '📡 Saluran',
                        'value' => 'Keamanan, Audit Trail & Pemeliharaan Sistem',
                        'inline' => true
                    ],
                    [
                        'name' => '⏱️ Waktu Server',
                        'value' => date('d/m/Y H:i:s') . ' WIB',
                        'inline' => false
                    ]
                ],
                'footer' => [
                    'text' => $appTitle . ' • ' . $appSubtitle
                ],
                'timestamp' => date('c')
            ]
        ]
    ];
} else {
    $payload = [
        'username' => $appTitle . ' [NOC Bot]',
        'embeds' => [
            [
                'title' => '📡 Uji Coba Koneksi Saluran Operasional NOC & Laporan',
                'description' => 'Koneksi Discord Webhook untuk saluran operasional teknisi dan rekapitulasi shift berhasil terhubung dengan sempurna!',
                'color' => 0x008080, // Teal
                'fields' => [
                    [
                        'name' => '👤 Penguji',
                        'value' => htmlspecialchars($currentUser['full_name'] ?? 'Superadmin') . ' (@' . htmlspecialchars($currentUser['username'] ?? 'superadmin') . ')',
                        'inline' => true
                    ],
                    [
                        'name' => '📋 Saluran',
                        'value' => 'Operasional Shift NOC, Logbook & Dokumen Tim',
                        'inline' => true
                    ],
                    [
                        'name' => '⏱️ Waktu Server',
                        'value' => date('d/m/Y H:i:s') . ' WIB',
                        'inline' => false
                    ]
                ],
                'footer' => [
                    'text' => $appTitle . ' • ' . $appSubtitle
                ],
                'timestamp' => date('c')
            ]
        ]
    ];
}

$res = send_discord_payload($webhookUrl, $payload);

if (!empty($res['success'])) {
    // Automatically persist the valid webhook URL to system_settings so it never gets lost
    if ($shouldSave) {
        $settingKey = ($channelType === 'security') ? 'discord_webhook_security' : 'discord_webhook_noc';
        set_system_setting($settingKey, $webhookUrl);
    }

    echo json_encode([
        'success' => true,
        'saved' => $shouldSave,
        'message' => 'Pesan uji coba berhasil terkirim ke Discord dan konfigurasi webhook otomatis tersimpan di sistem!'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $res['message'] ?? 'Gagal menghubungi Discord.'
    ]);
}
