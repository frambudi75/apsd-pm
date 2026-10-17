<?php
/**
 * PWA Manifest & App Icon Dynamic Synchronization
 * PT Angkasa Pura Sarana Digital - IASS SBU ICT KNO
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Generate a square PWA launcher icon centered on a transparent canvas
 */
function generate_single_pwa_icon(string $sourceFile, int $targetSize, string $destFile): bool {
    if (!extension_loaded('gd') || !file_exists($sourceFile)) {
        return false;
    }

    $info = @getimagesize($sourceFile);
    if (!$info) return false;

    $mime = $info['mime'] ?? '';
    $src = null;
    if ($mime === 'image/png') {
        $src = @imagecreatefrompng($sourceFile);
    } elseif ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $src = @imagecreatefromjpeg($sourceFile);
    } elseif ($mime === 'image/webp') {
        $src = @imagecreatefromwebp($sourceFile);
    }

    if (!$src) return false;

    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0) {
        @imagedestroy($src);
        return false;
    }

    $canvas = imagecreatetruecolor($targetSize, $targetSize);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $trans = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $targetSize, $targetSize, $trans);

    // 12% padding for maskable icon safe-zone (circle/squircle launchers)
    $avail = (int)($targetSize * 0.76);
    $scale = min($avail / $sw, $avail / $sh);
    $dw = max(1, (int)($sw * $scale));
    $dh = max(1, (int)($sh * $scale));
    $dx = (int)(($targetSize - $dw) / 2);
    $dy = (int)(($targetSize - $dh) / 2);

    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagesavealpha($canvas, true);

    $dir = dirname($destFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $res = @imagepng($canvas, $destFile, 8);
    @chmod($destFile, 0666);
    @imagedestroy($canvas);
    @imagedestroy($src);

    return $res;
}

/**
 * Synchronize manifest.json and PWA icons with current system branding settings
 */
function sync_pwa_manifest_and_icons(): bool {
    try {
        $appTitle = trim(get_system_setting('app_title', get_system_setting('company_name', 'IASS SBU ICT KNO')));
        $appSubtitle = trim(get_system_setting('app_subtitle', 'Sistem PM & NOC'));
        $rawLogo = trim(get_system_setting('app_logo_path', 'assets/img/logo_ias.png?v=3'));

        // Strip cache query string from logo path
        $logoRel = preg_replace('/\?.*$/', '', $rawLogo);
        $logoFull = __DIR__ . '/../' . ltrim($logoRel, '/\\');

        $brandDir = __DIR__ . '/../uploads/branding';
        if (!is_dir($brandDir)) {
            @mkdir($brandDir, 0777, true);
            @chmod($brandDir, 0777);
        }

        $icon192 = 'assets/img/icon-192.png';
        $icon512 = 'assets/img/icon-512.png';

        if (file_exists($logoFull)) {
            $dest192 = $brandDir . '/pwa-icon-192.png';
            $dest512 = $brandDir . '/pwa-icon-512.png';

            $gen192 = generate_single_pwa_icon($logoFull, 192, $dest192);
            $gen512 = generate_single_pwa_icon($logoFull, 512, $dest512);

            if ($gen192 && file_exists($dest192)) {
                $icon192 = 'uploads/branding/pwa-icon-192.png';
                @copy($dest192, __DIR__ . '/../assets/img/icon-192.png');
            }
            if ($gen512 && file_exists($dest512)) {
                $icon512 = 'uploads/branding/pwa-icon-512.png';
                @copy($dest512, __DIR__ . '/../assets/img/icon-512.png');
            }
        }

        $manifestData = [
            'id' => './',
            'name' => $appTitle . ($appSubtitle !== '' ? ' - ' . $appSubtitle : ''),
            'short_name' => mb_strimwidth($appTitle, 0, 16, ''),
            'description' => $appSubtitle !== '' ? $appSubtitle : 'Sistem Checklist Pemeliharaan Berkala & Logbook NOC',
            'start_url' => './',
            'scope' => './',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f8fafc',
            'theme_color' => '#008080',
            'categories' => ['business', 'productivity', 'utilities'],
            'icons' => [
                [
                    'src' => 'assets/img/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ],
                [
                    'src' => 'assets/img/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ],
                [
                    'src' => 'uploads/branding/pwa-icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ],
                [
                    'src' => 'uploads/branding/pwa-icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ]
            ],
            'shortcuts' => [
                [
                    'name' => 'Buat PM Indoor',
                    'short_name' => 'PM Indoor',
                    'description' => 'Formulir Checklist & Dokumentasi PM Indoor (7 Halaman)',
                    'url' => 'form_indoor',
                    'icons' => [['src' => $icon192, 'sizes' => '192x192']]
                ],
                [
                    'name' => 'Buat PM Outdoor',
                    'short_name' => 'PM Outdoor',
                    'description' => 'Formulir Checklist & Dokumentasi PM Outdoor Pole (13 Halaman)',
                    'url' => 'form_outdoor',
                    'icons' => [['src' => $icon192, 'sizes' => '192x192']]
                ],
                [
                    'name' => 'Catat Daily Activity',
                    'short_name' => 'Daily Activity',
                    'description' => 'Logbook Kegiatan Harian Shift NOC',
                    'url' => 'daily_activity',
                    'icons' => [['src' => $icon192, 'sizes' => '192x192']]
                ],
                [
                    'name' => 'Master Site Bandara',
                    'short_name' => 'Master Site',
                    'description' => 'Kelola data master site dan koordinat bandara',
                    'url' => 'sites',
                    'icons' => [['src' => $icon192, 'sizes' => '192x192']]
                ]
            ]
        ];

        $manifestFile = __DIR__ . '/../manifest.json';
        $json = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @file_put_contents($manifestFile, $json);
        @chmod($manifestFile, 0666);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
