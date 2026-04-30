<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;

final class TenantResolverService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function resolve(Request $request): ?array
    {
        $host = $request->headers['Host'] ?? $request->server['HTTP_HOST'] ?? '';
        $slug = (string) $request->route('slug', $request->query['tenant'] ?? '');

        if ($host !== '') {
            $statement = $this->database->pdo()->prepare(
                'SELECT r.*, rb.public_name, rb.logo_url, rb.cover_image_url, rb.favicon_url, rb.primary_color, rb.secondary_color,
                        rb.accent_color, rb.web_subdomain, rb.custom_domain, rb.portal_title, rb.portal_tagline, rb.welcome_text
                 FROM restaurants r
                 LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
                 WHERE rb.custom_domain = :host
                    OR rb.web_subdomain = SUBSTRING_INDEX(:host, ".", 1)
                 LIMIT 1'
            );
            $statement->execute(['host' => $host]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);

            if ($tenant) {
                return $tenant;
            }
        }

        if ($slug !== '') {
            $statement = $this->database->pdo()->prepare(
                'SELECT r.*, rb.public_name, rb.logo_url, rb.cover_image_url, rb.favicon_url, rb.primary_color, rb.secondary_color,
                        rb.accent_color, rb.web_subdomain, rb.custom_domain, rb.portal_title, rb.portal_tagline, rb.welcome_text
                 FROM restaurants r
                 LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
                 WHERE r.slug = :slug
                 LIMIT 1'
            );
            $statement->execute(['slug' => $slug]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);

            if ($tenant) {
                return $tenant;
            }
        }

        if (isset($_SESSION['user']['restaurant_id']) && $_SESSION['user']['restaurant_id'] !== null) {
            $statement = $this->database->pdo()->prepare(
                'SELECT r.*, rb.public_name, rb.logo_url, rb.cover_image_url, rb.favicon_url, rb.primary_color, rb.secondary_color,
                        rb.accent_color, rb.web_subdomain, rb.custom_domain, rb.portal_title, rb.portal_tagline, rb.welcome_text
                 FROM restaurants r
                 LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
                 WHERE r.id = :restaurant_id
                 LIMIT 1'
            );
            $statement->execute(['restaurant_id' => (int) $_SESSION['user']['restaurant_id']]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC);

            return $tenant ?: null;
        }

        return null;
    }
}
