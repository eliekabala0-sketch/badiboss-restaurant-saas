<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

final class NotificationController
{
    public function feed(Request $request): void
    {
        $actor = current_user();
        if (!is_array($actor) || (int) ($actor['id'] ?? 0) <= 0) {
            Response::json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $restaurantId = (int) ($request->query['restaurant_id'] ?? current_restaurant_id());
        $userId = (int) ($actor['id'] ?? 0);
        $sinceId = max(0, (int) ($request->query['since_id'] ?? 0));
        $limit = max(1, min(20, (int) ($request->query['limit'] ?? 10)));

        session_release_read_lock();

        $rows = Container::getInstance()->get('uiNotifications')->listForUser($restaurantId, $userId, $sinceId, $limit);
        $lastId = $sinceId;
        foreach ($rows as $row) {
            $lastId = max($lastId, (int) ($row['id'] ?? 0));
        }

        Response::json([
            'data' => [
                'notifications' => array_map(static function (array $row): array {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'event_code' => (string) ($row['event_code'] ?? ''),
                        'level' => (string) ($row['level'] ?? 'info'),
                        'title' => (string) ($row['title'] ?? ''),
                        'message' => (string) ($row['message'] ?? ''),
                        'target_url' => (string) ($row['target_url'] ?? ''),
                        'event_key' => (string) ($row['event_key'] ?? ''),
                        'created_at' => (string) ($row['created_at'] ?? ''),
                    ];
                }, $rows),
                'last_id' => $lastId,
            ],
        ]);
    }
}
