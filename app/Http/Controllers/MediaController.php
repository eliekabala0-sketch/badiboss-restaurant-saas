<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;

final class MediaController
{
    public function restaurantUpload(Request $request): void
    {
        $restaurantCode = $this->sanitizePathSegment((string) $request->route('restaurantCode', ''));
        $filename = $this->resolveFilename($request);

        $this->serveUpload(
            base_path('public/uploads/restaurants/' . $restaurantCode . '/' . $filename),
            $this->fallbackKindFromFilename($filename)
        );
    }

    public function menuUpload(Request $request): void
    {
        $restaurantCode = $this->sanitizePathSegment((string) $request->route('restaurantCode', ''));
        $filename = $this->resolveFilename($request);

        $this->serveUpload(
            base_path('public/uploads/restaurants/' . $restaurantCode . '/menu/' . $filename),
            'photo'
        );
    }

    private function serveUpload(string $absolutePath, string $fallbackKind): void
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

        $this->renderFallback($fallbackKind);
    }

    private function renderFallback(string $kind): void
    {
        $dataUrl = restaurant_media_fallback_url($kind);
        if (str_starts_with($dataUrl, 'data:image/svg+xml;utf8,')) {
            header('Content-Type: image/svg+xml; charset=UTF-8');
            header('Cache-Control: public, max-age=604800');
            echo rawurldecode(substr($dataUrl, strlen('data:image/svg+xml;utf8,')));

            return;
        }

        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=604800');
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
