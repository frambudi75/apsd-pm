<?php
/**
 * Smart Image Compressor Utility
 * Resizes large smartphone camera photos and optimizes compression
 * preserving visual sharpness while reducing 5-15MB files to ~250-400KB
 * PT Angkasa Pura Sarana Digital
 */

function compress_and_save_image(string $sourcePath, string $destPath, int $maxDimension = 1600, int $quality = 82): bool {
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
            // Fallback copy directly if unsupported format
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

    // Save compressed file
    $result = false;
    if ($destExt === 'png') {
        $result = imagepng($destImg, $destPath, 6);
    } else {
        // High quality JPEG compression (quality 82 preserves crisp details and sharp text/watermarks)
        $result = imagejpeg($destImg, $destPath, $quality);
    }

    imagedestroy($srcImg);
    imagedestroy($destImg);

    return $result;
}
