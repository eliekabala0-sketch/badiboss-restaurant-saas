<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $viewFile = base_path('app/Views/' . $template . '.php');

        if (!is_file($viewFile)) {
            http_response_code(500);
            echo 'View not found';
            return;
        }

        if (!array_key_exists('current_restaurant_context', $data) && current_user() !== null && (current_user()['scope'] ?? null) !== 'super_admin') {
            $restaurantId = (int) (current_user()['restaurant_id'] ?? 0);
            $data['current_restaurant_context'] = \App\Core\Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
            $data['current_subscription_context'] = \App\Core\Container::getInstance()->get('subscriptionService')->summaryForRestaurant($restaurantId);
        }

        extract($data, EXTR_SKIP);
        require base_path('app/Views/layouts/app.php');
    }
}
