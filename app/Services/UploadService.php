<?php

declare(strict_types=1);

namespace App\Services;

final class UploadService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_SIZE_BYTES = 5_242_880;

    public function storeRestaurantImage(array $file, string $restaurantCode, string $kind): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload image invalide.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_SIZE_BYTES) {
            throw new \RuntimeException('Image trop volumineuse ou vide.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Extension image non autorisee.');
        }

        $dimensions = @getimagesize((string) ($file['tmp_name'] ?? ''));
        if ($dimensions === false) {
            throw new \RuntimeException('Le fichier televerse n est pas une image valide.');
        }

        $targetDirectory = base_path('public/uploads/restaurants/' . $restaurantCode);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Impossible de preparer le dossier de televersement.');
        }

        $assetConfig = $this->assetConfig($kind, $extension);
        $filename = $kind . '-' . date('YmdHis') . '.' . $assetConfig['extension'];
        $targetPath = $targetDirectory . '/' . $filename;

        if ($this->canProcessImage($assetConfig['extension'])) {
            $this->storeProcessedImage((string) $file['tmp_name'], $targetPath, $assetConfig);
        } elseif (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Impossible d enregistrer le fichier televerse.');
        }

        return '/uploads/restaurants/' . $restaurantCode . '/' . $filename;
    }

    private function assetConfig(string $kind, string $sourceExtension): array
    {
        return match ($kind) {
            'favicon' => [
                'max_width' => 256,
                'max_height' => 256,
                'quality' => 88,
                'compression' => 7,
                'extension' => 'png',
            ],
            'photo' => [
                'max_width' => 1920,
                'max_height' => 1080,
                'quality' => 82,
                'compression' => 7,
                'extension' => $sourceExtension === 'png' ? 'png' : 'jpg',
            ],
            default => [
                'max_width' => 1200,
                'max_height' => 1200,
                'quality' => 84,
                'compression' => 7,
                'extension' => $sourceExtension === 'png' ? 'png' : 'jpg',
            ],
        };
    }

    private function canProcessImage(string $extension): bool
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled')) {
            return match ($extension) {
                'jpg' => function_exists('imagejpeg'),
                'png' => function_exists('imagepng'),
                'webp' => function_exists('imagewebp'),
                default => false,
            };
        }

        return stripos(PHP_OS_FAMILY, 'Windows') !== false;
    }

    private function storeProcessedImage(string $sourcePath, string $targetPath, array $config): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            $this->storeProcessedImageWithPowerShell($sourcePath, $targetPath, $config);
            return;
        }

        $binary = @file_get_contents($sourcePath);
        if ($binary === false) {
            throw new \RuntimeException('Impossible de lire l image televersee.');
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new \RuntimeException('Le fichier televerse n est pas une image exploitable.');
        }

        $sourceWidth = (int) imagesx($source);
        $sourceHeight = (int) imagesy($source);
        [$targetWidth, $targetHeight] = $this->targetDimensions(
            $sourceWidth,
            $sourceHeight,
            (int) $config['max_width'],
            (int) $config['max_height']
        );

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            imagedestroy($source);
            throw new \RuntimeException('Impossible de preparer le redimensionnement de l image.');
        }

        if ((string) $config['extension'] === 'png') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        } else {
            $background = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $background);
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $stored = match ((string) $config['extension']) {
            'png' => imagepng($canvas, $targetPath, (int) $config['compression']),
            'webp' => imagewebp($canvas, $targetPath, (int) $config['quality']),
            default => imagejpeg($canvas, $targetPath, (int) $config['quality']),
        };

        imagedestroy($canvas);
        imagedestroy($source);

        if ($stored !== true) {
            throw new \RuntimeException('Impossible de compresser l image televersee.');
        }
    }

    private function storeProcessedImageWithPowerShell(string $sourcePath, string $targetPath, array $config): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'badiboss-upload-');
        if ($scriptPath === false) {
            throw new \RuntimeException('Impossible de preparer le traitement image.');
        }

        $ps1Path = $scriptPath . '.ps1';
        @rename($scriptPath, $ps1Path);

        $script = <<<'PS1'
param(
    [string]$SourcePath,
    [string]$TargetPath,
    [int]$MaxWidth,
    [int]$MaxHeight,
    [string]$Format,
    [int]$Quality
)

Add-Type -AssemblyName System.Drawing

$source = [System.Drawing.Image]::FromFile($SourcePath)
try {
    $ratio = [Math]::Min([Math]::Min($MaxWidth / $source.Width, $MaxHeight / $source.Height), 1)
    $targetWidth = [Math]::Max(1, [int][Math]::Round($source.Width * $ratio))
    $targetHeight = [Math]::Max(1, [int][Math]::Round($source.Height * $ratio))

    $bitmap = New-Object System.Drawing.Bitmap($targetWidth, $targetHeight)
    try {
        $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
        try {
            if ($Format -eq 'png') {
                $graphics.Clear([System.Drawing.Color]::Transparent)
            } else {
                $graphics.Clear([System.Drawing.Color]::White)
            }

            $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
            $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $graphics.DrawImage($source, 0, 0, $targetWidth, $targetHeight)
        } finally {
            $graphics.Dispose()
        }

        if ($Format -eq 'png') {
            $bitmap.Save($TargetPath, [System.Drawing.Imaging.ImageFormat]::Png)
        } else {
            $encoder = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
            $encoderParams = New-Object System.Drawing.Imaging.EncoderParameters(1)
            $encoderParams.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter([System.Drawing.Imaging.Encoder]::Quality, [long]$Quality)
            $bitmap.Save($TargetPath, $encoder, $encoderParams)
        }
    } finally {
        $bitmap.Dispose()
    }
} finally {
    $source.Dispose()
}
PS1;

        if (@file_put_contents($ps1Path, $script) === false) {
            @unlink($ps1Path);
            throw new \RuntimeException('Impossible de preparer le script de compression image.');
        }

        $command = sprintf(
            'powershell.exe -NoProfile -ExecutionPolicy Bypass -File %s -SourcePath %s -TargetPath %s -MaxWidth %d -MaxHeight %d -Format %s -Quality %d 2>&1',
            escapeshellarg($ps1Path),
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath),
            (int) $config['max_width'],
            (int) $config['max_height'],
            escapeshellarg((string) $config['extension']),
            (int) $config['quality']
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($ps1Path);

        if ($exitCode !== 0 || !is_file($targetPath)) {
            throw new \RuntimeException('Impossible de compresser l image televersee.');
        }
    }

    private function targetDimensions(int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight): array
    {
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return [$maxWidth, $maxHeight];
        }

        $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);

        return [
            max(1, (int) round($sourceWidth * $ratio)),
            max(1, (int) round($sourceHeight * $ratio)),
        ];
    }
}
