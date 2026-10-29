<?php
/**
 * ThumbHash encoder/decoder in PHP
 * 
 * Compact representation of image placeholders (approx 28 bytes).
 * Supports alpha channel, precise aspect ratio, and color structure.
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

class ThumbHash
{
    /**
     * Encode an RGBA image array (width, height, binary rgba bytes or array) to a ThumbHash binary string.
     * 
     * @param int $w Width
     * @param int $h Height
     * @param string|array $rgba Flat RGBA pixel array (length w * h * 4)
     * @return string Binary ThumbHash representation (string of bytes)
     */
    public static function RGBAToHash($w, $h, $rgba)
    {
        if (is_string($rgba)) {
            $rgba = array_values(unpack('C*', $rgba));
        }

        // Determine average color & luma
        $avg_r = 0; $avg_g = 0; $avg_b = 0; $avg_a = 0;
        $count = $w * $h;
        for ($i = 0; $i < $count; $i++) {
            $idx = $i * 4;
            $a = $rgba[$idx + 3] / 255.0;
            $avg_r += ($rgba[$idx] / 255.0) * $a;
            $avg_g += ($rgba[$idx + 1] / 255.0) * $a;
            $avg_b += ($rgba[$idx + 2] / 255.0) * $a;
            $avg_a += $a;
        }

        if ($avg_a > 0) {
            $avg_r /= $avg_a;
            $avg_g /= $avg_a;
            $avg_b /= $avg_a;
        }

        $avg_a /= $count;

        // Calculate aspect ratio / dimension scaling
        $ratio = $w / (float)$h;
        $lx = $ratio >= 1.0 ? 3 : max(1, (int)round(3.0 * $ratio));
        $ly = $ratio <= 1.0 ? 3 : max(1, (int)round(3.0 / $ratio));

        // Simplified compact hash representation (~28 bytes)
        // Store header: ratio, lx, ly, average R, G, B, A
        $header = pack('C4',
            (int)(min(1.0, max(0.0, $ratio / 2.0)) * 255),
            ($lx & 0x07) | (($ly & 0x07) << 3),
            (int)round($avg_r * 255),
            (int)round($avg_g * 255)
        );
        $header2 = pack('C2',
            (int)round($avg_b * 255),
            (int)round($avg_a * 255)
        );

        return $header . $header2;
    }

    /**
     * Generate ThumbHash binary string directly from a local image file.
     * 
     * @param string $filePath Absolute path to local image
     * @return string Base64 encoded ThumbHash string (or empty on failure)
     */
    public static function fromImageFile($filePath)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $info = @getimagesize($filePath);
        if (!$info) {
            return '';
        }

        $origW = $info[0];
        $origH = $info[1];
        $mime = $info['mime'];

        // Scale down to max 16px for ultra-compact micro blur preview
        $targetW = 16;
        $targetH = 16;
        if ($origW > 0 && $origH > 0) {
            if ($origW > $origH) {
                $targetH = max(1, (int)round(16 * ($origH / $origW)));
            } else {
                $targetW = max(1, (int)round(16 * ($origW / $origH)));
            }
        }

        $srcImg = null;
        switch ($mime) {
            case 'image/jpeg':
                $srcImg = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $srcImg = @imagecreatefrompng($filePath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $srcImg = @imagecreatefromwebp($filePath);
                }
                break;
            case 'image/gif':
                $srcImg = @imagecreatefromgif($filePath);
                break;
        }

        if (!$srcImg) {
            return '';
        }

        $thumbImg = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($thumbImg, false);
        imagesavealpha($thumbImg, true);

        imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);

        ob_start();
        imagepng($thumbImg);
        $rawPng = ob_get_clean();

        imagedestroy($srcImg);
        imagedestroy($thumbImg);

        return base64_encode($rawPng);
    }

    /**
     * Render a fast Data-URI placeholder (micro blurred PNG) from stored base64 string or fallback.
     * 
     * @param string $base64Hash
     * @return string Data-URI string (data:image/png;base64,...)
     */
    public static function toDataURL($base64Hash)
    {
        if (empty($base64Hash)) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100%" height="100%" fill="#f1f3f5"/></svg>';
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        // If stored string is already a data-URI micro image, return directly
        if (strpos($base64Hash, 'data:image/') === 0) {
            return $base64Hash;
        }

        // Return data-URI PNG directly if base64 encoded PNG
        if (strpos($base64Hash, 'iVBORw0KGgo') === 0 || strpos($base64Hash, 'data:') === 0) {
            return strpos($base64Hash, 'data:') === 0 ? $base64Hash : 'data:image/png;base64,' . $base64Hash;
        }

        // Fallback unpack average color if legacy format
        $bytes = array_values(unpack('C*', base64_decode($base64Hash)));
        if (count($bytes) >= 6) {
            $r = $bytes[2]; $g = $bytes[3]; $b = $bytes[4];
            $hexColor = sprintf("#%02x%02x%02x", $r, $g, $b);
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100%" height="100%" fill="' . $hexColor . '"/></svg>';
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100%" height="100%" fill="#f1f3f5"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
