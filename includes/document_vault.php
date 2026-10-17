<?php
/**
 * Document Vault Utilities & Compression Engine
 * IASS SBU ICT KNO
 */

if (!function_exists('process_and_compress_document_file')) {
    /**
     * Optimize and compress uploaded document file
     * - For images (JPG, PNG, WebP): resize huge dimensions (>1920px), compress quality, preserving readability
     * - For all files: apply maximum gzip compression (gzencode level 9)
     * Returns array: ['final_data', 'raw_size', 'compressed_size', 'is_compressed', 'mime_type']
     */
    function process_and_compress_document_file(string $tmpPath, string $ext, int $originalUploadSize): array {
        $ext = strtolower($ext);
        $mimeType = @mime_content_type($tmpPath) ?: 'application/octet-stream';
        $rawSize = $originalUploadSize > 0 ? $originalUploadSize : (int)@filesize($tmpPath);

        // 1. Optimize images if GD is available
        if (extension_loaded('gd') && in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp'], true)) {
            try {
                $rawContent = @file_get_contents($tmpPath);
                if ($rawContent !== false && strlen($rawContent) > 0) {
                    $img = @imagecreatefromstring($rawContent);
                    if ($img !== false) {
                        $w = imagesx($img);
                        $h = imagesy($img);
                        $maxDim = 1920;
                        
                        if ($w > $maxDim || $h > $maxDim) {
                            $scale = min($maxDim / $w, $maxDim / $h);
                            $nw = max(1, (int)round($w * $scale));
                            $nh = max(1, (int)round($h * $scale));
                        } else {
                            $nw = $w;
                            $nh = $h;
                        }

                        $canvas = imagecreatetruecolor($nw, $nh);

                        if ($ext === 'png') {
                            imagealphablending($canvas, false);
                            imagesavealpha($canvas, true);
                            $trans = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                            imagefilledrectangle($canvas, 0, 0, $nw, $nh, $trans);
                            imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

                            $optPath = $tmpPath . '_opt.png';
                            imagepng($canvas, $optPath, 8);
                            if (file_exists($optPath) && filesize($optPath) > 0) {
                                $optSize = filesize($optPath);
                                if ($optSize < strlen($rawContent)) {
                                    $rawContent = file_get_contents($optPath);
                                }
                                @unlink($optPath);
                            }
                        } elseif ($ext === 'webp') {
                            imagealphablending($canvas, false);
                            imagesavealpha($canvas, true);
                            imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

                            $optPath = $tmpPath . '_opt.webp';
                            imagewebp($canvas, $optPath, 80);
                            if (file_exists($optPath) && filesize($optPath) > 0) {
                                $optSize = filesize($optPath);
                                if ($optSize < strlen($rawContent)) {
                                    $rawContent = file_get_contents($optPath);
                                }
                                @unlink($optPath);
                            }
                        } else {
                            // JPEG / JPG / BMP
                            if (function_exists('exif_read_data') && ($ext === 'jpg' || $ext === 'jpeg')) {
                                $exif = @exif_read_data($tmpPath);
                                $orientation = $exif['Orientation'] ?? 1;
                                if ($orientation === 3) {
                                    $img = imagerotate($img, 180, 0);
                                } elseif ($orientation === 6) {
                                    $img = imagerotate($img, -90, 0);
                                    $tempW = $nw; $nw = $nh; $nh = $tempW;
                                    imagedestroy($canvas);
                                    $canvas = imagecreatetruecolor($nw, $nh);
                                } elseif ($orientation === 8) {
                                    $img = imagerotate($img, 90, 0);
                                    $tempW = $nw; $nw = $nh; $nh = $tempW;
                                    imagedestroy($canvas);
                                    $canvas = imagecreatetruecolor($nw, $nh);
                                }
                            }

                            imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, imagesx($img), imagesy($img));

                            $optPath = $tmpPath . '_opt.jpg';
                            imagejpeg($canvas, $optPath, 80);
                            if (file_exists($optPath) && filesize($optPath) > 0) {
                                $optSize = filesize($optPath);
                                if ($optSize < strlen($rawContent)) {
                                    $rawContent = file_get_contents($optPath);
                                }
                                @unlink($optPath);
                            }
                        }

                        imagedestroy($canvas);
                        imagedestroy($img);
                    }
                }
            } catch (Throwable $t) {
                // Fallback to direct file content on any image processing exception
            }
        }

        if (!isset($rawContent) || $rawContent === false) {
            $rawContent = file_get_contents($tmpPath);
        }
        if ($rawContent === false || strlen($rawContent) === 0) {
            return ['error' => 'Gagal membaca berkas yang diunggah.'];
        }

        // 2. High-compression gzip (level 9 for maximum storage reduction)
        $compData = @gzencode($rawContent, 9);
        if ($compData !== false) {
            $finalData = $compData;
            $isCompressed = 1;
            $compSize = strlen($compData);
        } else {
            $finalData = $rawContent;
            $isCompressed = 0;
            $compSize = strlen($rawContent);
        }

        return [
            'final_data' => $finalData,
            'raw_size' => $rawSize,
            'compressed_size' => $compSize,
            'is_compressed' => $isCompressed,
            'mime_type' => $mimeType
        ];
    }
}
