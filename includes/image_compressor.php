<?php
/**
 * Smart Image Compressor Utility
 * Resizes large smartphone camera photos and optimizes compression
 * preserving visual sharpness while reducing 5-15MB files to ~250-400KB
 * PT Angkasa Pura Sarana Digital
 */

function compress_and_save_image(string $sourcePath, string $destPath, int $maxDimension = 1600, int $quality = 85, ?array $watermark = null): bool {
    @ini_set('memory_limit', '512M');
    if (!file_exists($sourcePath)) {
        return false;
    }

    $info = @getimagesize($sourcePath);
    if (!$info) {
        return false;
    }

    $mime = $info['mime'];
    $srcWidth = $info[0];
    $srcHeight = $info[1];

    if ($srcWidth <= 0 || $srcHeight <= 0) {
        return false;
    }

    // Create image resource based on mime type
    $srcImg = null;
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $srcImg = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $srcImg = @imagecreatefrompng($sourcePath);
            break;
        case 'image/webp':
            $srcImg = @imagecreatefromwebp($sourcePath);
            break;
        default:
            return @copy($sourcePath, $destPath);
    }

    if (!$srcImg) {
        return @copy($sourcePath, $destPath);
    }

    // Auto-fix orientation from EXIF if available (JPEG only)
    if (function_exists('exif_read_data') && ($mime === 'image/jpeg' || $mime === 'image/jpg')) {
        $exif = @exif_read_data($sourcePath);
        if ($exif && !empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $srcImg = imagerotate($srcImg, 180, 0);
                    break;
                case 6:
                    $srcImg = imagerotate($srcImg, -90, 0);
                    $temp = $srcWidth;
                    $srcWidth = $srcHeight;
                    $srcHeight = $temp;
                    break;
                case 8:
                    $srcImg = imagerotate($srcImg, 90, 0);
                    $temp = $srcWidth;
                    $srcWidth = $srcHeight;
                    $srcHeight = $temp;
                    break;
            }
        }
    }

    // Calculate proportional downscaled dimensions
    $targetWidth = $srcWidth;
    $targetHeight = $srcHeight;

    if ($srcWidth > $maxDimension || $srcHeight > $maxDimension) {
        if ($srcWidth > $srcHeight) {
            $targetWidth = $maxDimension;
            $targetHeight = (int) round(($srcHeight / $srcWidth) * $maxDimension);
        } else {
            $targetHeight = $maxDimension;
            $targetWidth = (int) round(($srcWidth / $srcHeight) * $maxDimension);
        }
    }

    // Create truecolor canvas with smooth bicubic resampling
    $destImg = imagecreatetruecolor($targetWidth, $targetHeight);

    // Preserve transparency for PNG if saving as PNG
    $destExt = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    if ($destExt === 'png') {
        imagealphablending($destImg, false);
        imagesavealpha($destImg, true);
        $transparent = imagecolorallocatealpha($destImg, 255, 255, 255, 127);
        imagefilledrectangle($destImg, 0, 0, $targetWidth, $targetHeight, $transparent);
    } else {
        // Fill white background for JPEG
        $white = imagecolorallocate($destImg, 255, 255, 255);
        imagefilledrectangle($destImg, 0, 0, $targetWidth, $targetHeight, $white);
    }

    imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);

    // Apply official APSD inspection watermark if metadata is supplied
    if ($watermark && !empty($watermark['slot'])) {
        apply_server_watermark($destImg, $targetWidth, $targetHeight, $watermark);
    }

    // Save compressed file
    $result = false;
    if ($destExt === 'png') {
        $result = imagepng($destImg, $destPath, 6);
    } else {
        // High quality JPEG compression (preserves crisp details and sharp text/watermarks)
        $result = imagejpeg($destImg, $destPath, $quality);
    }

    imagedestroy($srcImg);
    imagedestroy($destImg);

    return $result;
}

/**
 * Apply official APSD inspection timestamp & GPS watermark directly to image
 */
function apply_server_watermark(&$img, int $width, int $height, array $meta): void {
    $isPortrait = ($height > $width);
    $baseWidth = $isPortrait ? 760 : 1050;
    $scale = max(0.65, $width / $baseWidth);
    $boxH = (int) max(116, round(($isPortrait ? 134 : 116) * $scale));
    $pad = (int) max(12, round(14 * $scale));
    $yStart = $height - $boxH;

    // Dark semi-transparent banner
    $bg = imagecolorallocatealpha($img, 15, 23, 42, 20); // ~84% opacity
    imagefilledrectangle($img, 0, $yStart, $width, $height, $bg);

    // Teal vertical accent line
    $teal = imagecolorallocate($img, 0, 128, 128);
    imagefilledrectangle($img, $pad, $yStart + (int)(6 * $scale), $pad + (int)(4 * $scale), $height - (int)(10 * $scale), $teal);

    $startX = $pad + (int)(12 * $scale);
    $maxTextW = $width - $startX - $pad;
    $fontFile = __DIR__ . '/../assets/fonts/arial.ttf';
    $hasTtf = function_exists('imagettftext') && file_exists($fontFile);

    $headerStr = 'IASS SBU ICT KNO | PM INSPECTION';
    $timeStr = 'WAKTU : ' . ($meta['date'] ?? date('d/m/Y H:i:s') . ' WIB');
    $siteStr = 'SITE  : ' . ($meta['site'] ?? 'Site KNO') . (!empty($meta['location']) ? ' (' . $meta['location'] . ')' : '');
    $gpsStr = 'GPS   : ' . ($meta['gps'] ?? 'GPS: -');
    $slotStr = 'FOTO  : Slot ' . ($meta['slot'] ?? '') . (!empty($meta['title']) ? ' (' . $meta['title'] . ')' : '');

    $maxChars = $isPortrait ? 38 : 55;
    if (strlen($siteStr) > $maxChars) $siteStr = substr($siteStr, 0, $maxChars - 3) . '...';
    if (strlen($slotStr) > $maxChars) $slotStr = substr($slotStr, 0, $maxChars - 3) . '...';

    if ($hasTtf) {
        $colorCyan = imagecolorallocate($img, 56, 189, 248);
        $colorWhite = imagecolorallocate($img, 255, 255, 255);
        $colorYellow = imagecolorallocate($img, 253, 224, 71);
        $colorGray = imagecolorallocate($img, 226, 232, 240);
        $colorShadow = imagecolorallocatealpha($img, 0, 0, 0, 40);

        $draw = function(int $size, int $yOffset, string $text, int $color) use (&$img, $fontFile, $startX, $yStart, $scale, $colorShadow, $maxTextW) {
            $fontSize = (int) max(8, round($size * $scale));
            // Ensure text fits within maxTextW
            $bbox = @imagettfbbox($fontSize, 0, $fontFile, $text);
            if ($bbox) {
                $textWidth = abs($bbox[4] - $bbox[0]);
                while ($textWidth > $maxTextW && $fontSize > 7) {
                    $fontSize--;
                    $bbox = @imagettfbbox($fontSize, 0, $fontFile, $text);
                    $textWidth = abs($bbox[4] - $bbox[0]);
                }
            }
            $y = $yStart + (int) round($yOffset * $scale);
            imagettftext($img, $fontSize, 0, $startX + 1, $y + 1, $colorShadow, $fontFile, $text);
            imagettftext($img, $fontSize, 0, $startX, $y, $color, $fontFile, $text);
        };

        $draw(10, 20, $headerStr, $colorCyan);
        $draw(12, 40, $timeStr, $colorWhite);
        $draw(11, 58, $siteStr, $colorGray);
        $draw(11, 76, $gpsStr, $colorYellow);
        $draw(11, 94, $slotStr, $colorGray);
    } else {
        $colorCyan = imagecolorallocate($img, 56, 189, 248);
        $colorWhite = imagecolorallocate($img, 255, 255, 255);
        $colorYellow = imagecolorallocate($img, 253, 224, 71);
        $colorGray = imagecolorallocate($img, 226, 232, 240);

        imagestring($img, 3, $startX, $yStart + 6, $headerStr, $colorCyan);
        imagestring($img, 4, $startX, $yStart + 26, $timeStr, $colorWhite);
        imagestring($img, 3, $startX, $yStart + 46, $siteStr, $colorGray);
        imagestring($img, 3, $startX, $yStart + 66, $gpsStr, $colorYellow);
        imagestring($img, 3, $startX, $yStart + 86, $slotStr, $colorGray);
    }
}
