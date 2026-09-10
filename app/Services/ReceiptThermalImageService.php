<?php

namespace App\Services;

class ReceiptThermalImageService
{
    /**
     * Create a 1-bit-style thermal PNG: flatten transparency, grayscale,
     * resize, sharpen, then Floyd–Steinberg dither. Returns false if GD cannot
     * read the source.
     */
    public function optimize(string $sourcePath, string $destinationPath, int $maxWidth = 384, int $threshold = 128): bool
    {
        if (! is_file($sourcePath) || ! function_exists('imagecreatetruecolor')) {
            return false;
        }

        $src = $this->load($sourcePath);
        if (! $src) {
            return false;
        }

        $src = $this->flatten($src);
        $src = $this->resize($src, max(32, $maxWidth));
        $this->grayscale($src);
        $this->sharpen($src);
        $this->floydSteinberg($src, $threshold);

        $dir = dirname($destinationPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $ok = imagepng($src, $destinationPath, 6);
        imagedestroy($src);

        return (bool) $ok;
    }

    /**
     * @param  \GdImage|resource  $im
     */
    public function floydSteinberg($im, int $threshold = 128): void
    {
        $width = imagesx($im);
        $height = imagesy($im);
        imagefilter($im, IMG_FILTER_GRAYSCALE);

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $pixels[$y] = [];
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $pixels[$y][$x] = ($rgb >> 16) & 0xFF;
            }
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $old = max(0, min(255, (int) round($pixels[$y][$x])));
                $new = $old < $threshold ? 0 : 255;
                $err = $old - $new;
                $pixels[$y][$x] = $new;
                if ($x + 1 < $width) {
                    $pixels[$y][$x + 1] += $err * 7 / 16;
                }
                if ($y + 1 < $height) {
                    if ($x > 0) {
                        $pixels[$y + 1][$x - 1] += $err * 3 / 16;
                    }
                    $pixels[$y + 1][$x] += $err * 5 / 16;
                    if ($x + 1 < $width) {
                        $pixels[$y + 1][$x + 1] += $err * 1 / 16;
                    }
                }
            }
        }

        $black = imagecolorallocate($im, 0, 0, 0);
        $white = imagecolorallocate($im, 255, 255, 255);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($im, $x, $y, $pixels[$y][$x] < $threshold ? $black : $white);
            }
        }
    }

    /**
     * @return \GdImage|resource|null
     */
    private function load(string $path)
    {
        $info = @getimagesize($path);
        if (! is_array($info)) {
            return null;
        }

        return match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => null,
        };
    }

    /**
     * @param  \GdImage|resource  $src
     * @return \GdImage|resource
     */
    private function flatten($src)
    {
        $width = imagesx($src);
        $height = imagesy($src);
        $out = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $width, $height, $white);
        imagealphablending($out, true);
        imagecopy($out, $src, 0, 0, 0, 0, $width, $height);
        imagedestroy($src);

        return $out;
    }

    /**
     * @param  \GdImage|resource  $src
     * @return \GdImage|resource
     */
    private function resize($src, int $maxWidth)
    {
        $width = imagesx($src);
        $height = imagesy($src);
        if ($width <= $maxWidth) {
            return $src;
        }
        $nextW = $maxWidth;
        $nextH = max(1, (int) round($height * ($maxWidth / $width)));
        $out = imagecreatetruecolor($nextW, $nextH);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $nextW, $nextH, $white);
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nextW, $nextH, $width, $height);
        imagedestroy($src);

        return $out;
    }

    /**
     * @param  \GdImage|resource  $im
     */
    private function grayscale($im): void
    {
        imagefilter($im, IMG_FILTER_GRAYSCALE);
    }

    /**
     * @param  \GdImage|resource  $im
     */
    private function sharpen($im): void
    {
        $matrix = [
            [0, -1, 0],
            [-1, 5, -1],
            [0, -1, 0],
        ];
        imageconvolution($im, $matrix, 1, 0);
    }
}
