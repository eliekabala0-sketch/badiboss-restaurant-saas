<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class MediaController
{
    public function restaurantUpload(Request $request): void
    {
        $restaurantCode = $this->sanitizePathSegment((string) $request->route('restaurantCode', ''));
        $filename = $this->resolveFilename($request);

        $this->serveUpload(
            base_path('public/uploads/restaurants/' . $restaurantCode . '/' . $filename),
            $this->fallbackKindFromFilename($filename),
            $restaurantCode,
            $filename
        );
    }

    public function menuUpload(Request $request): void
    {
        $restaurantCode = $this->sanitizePathSegment((string) $request->route('restaurantCode', ''));
        $filename = $this->resolveFilename($request);

        $this->serveUpload(
            base_path('public/uploads/restaurants/' . $restaurantCode . '/menu/' . $filename),
            'photo',
            $restaurantCode,
            $filename
        );
    }

    private function serveUpload(string $absolutePath, string $fallbackKind, string $restaurantCode, string $filename): void
    {
        if ($absolutePath !== '' && is_file($absolutePath) && is_readable($absolutePath)) {
            $mime = (string) (mime_content_type($absolutePath) ?: 'application/octet-stream');
            $mtime = (int) filemtime($absolutePath);
            $size = (int) filesize($absolutePath);
            $etag = '"' . sha1($absolutePath . '|' . $mtime . '|' . $size) . '"';
            $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=31536000, immutable');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModified);

            $clientEtag = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
            $clientModified = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
            if ($clientEtag === $etag || ($clientModified !== '' && strtotime($clientModified) >= $mtime)) {
                http_response_code(304);
                return;
            }

            readfile($absolutePath);

            return;
        }

        if ($this->serveMirroredUpload($restaurantCode, $filename)) {
            return;
        }

        $this->renderFallback($fallbackKind);
    }

    private function serveMirroredUpload(string $restaurantCode, string $filename): bool
    {
        if ($restaurantCode === '' || $filename === '') {
            return false;
        }

        try {
            $pdo = Container::getInstance()->get('db')->pdo();
            $table = $pdo->query("SHOW TABLES LIKE 'restaurant_media_assets'")->fetchColumn();
            if ($table === false) {
                return false;
            }

            $statement = $pdo->prepare(
                'SELECT mime_type, file_size, content, updated_at
                 FROM restaurant_media_assets
                 WHERE restaurant_code = :restaurant_code AND filename = :filename
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $statement->execute([
                'restaurant_code' => $restaurantCode,
                'filename' => $filename,
            ]);
            $asset = $statement->fetch(\PDO::FETCH_ASSOC);
            if ($asset === false || !is_string($asset['content'] ?? null) || $asset['content'] === '') {
                return false;
            }

            $updatedAt = strtotime((string) ($asset['updated_at'] ?? '')) ?: time();
            $etag = '"' . sha1($restaurantCode . '|' . $filename . '|' . (string) ($asset['file_size'] ?? 0) . '|' . (string) ($asset['updated_at'] ?? '')) . '"';
            header('Content-Type: ' . (string) ($asset['mime_type'] ?? 'application/octet-stream'));
            header('Cache-Control: public, max-age=31536000, immutable');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $updatedAt) . ' GMT');
            header('X-Badiboss-Media-Source: database');

            $clientEtag = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
            $clientModified = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
            if ($clientEtag === $etag || ($clientModified !== '' && strtotime($clientModified) >= $updatedAt)) {
                http_response_code(304);
                return true;
            }

            echo $asset['content'];

            return true;
        } catch (\Throwable $exception) {
            error_log('[MEDIA_DB_FALLBACK] ' . $exception->getMessage());

            return false;
        }
    }

    private function renderFallback(string $kind): void
    {
        $dataUrl = restaurant_media_fallback_url($kind);
        if (str_starts_with($dataUrl, 'data:image/svg+xml;utf8,')) {
            header('Content-Type: image/svg+xml; charset=UTF-8');
            header('Cache-Control: public, max-age=3600');
            header('X-Badiboss-Media-Fallback: stable');
            echo rawurldecode(substr($dataUrl, strlen('data:image/svg+xml;utf8,')));

            return;
        }

        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        header('X-Badiboss-Media-Fallback: stable');
        echo '';
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['..', '/', '\\'], '', $value);

        return preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';
    }

    private function resolveFilename(Request $request): string
    {
        $raw = (string) ($request->route('filename', '') ?: ($request->query['file'] ?? ''));

        return $this->sanitizePathSegment($raw);
    }

    private function fallbackKindFromFilename(string $filename): string
    {
        $name = strtolower($filename);
        if (str_contains($name, 'favicon')) {
            return 'favicon';
        }
        if (str_contains($name, 'photo') || str_contains($name, 'cover')) {
            return 'photo';
        }

        return 'logo';
    }
}
