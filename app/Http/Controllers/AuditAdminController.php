<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class AuditAdminController
{
    public function index(Request $request): void
    {
        authorize_access('platform.audit.view');
        $filters = [
            'restaurant_id' => $request->query['restaurant_id'] ?? '',
            'user_id' => $request->query['user_id'] ?? '',
            'module_name' => $request->query['module_name'] ?? '',
            'action_name' => $request->query['action_name'] ?? '',
            'q' => $request->query['q'] ?? '',
            'date_from' => $request->query['date_from'] ?? '',
            'date_to' => $request->query['date_to'] ?? '',
        ];

        view('super-admin/audit/index', [
            'title' => 'Journal d audit',
            'rows' => Container::getInstance()->get('auditQuery')->search($filters),
            'filters' => $filters,
            'restaurants' => Container::getInstance()->get('restaurantAdmin')->listRestaurants(),
            'users' => Container::getInstance()->get('userAdmin')->listUsers(),
        ]);

        audit_access('audit', null, 'screens', 'audit-index', 'Consultation journal d audit');
    }
}
