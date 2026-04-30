<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class PlatformSettingsController
{
    public function index(Request $request): void
    {
        authorize_access('platform.settings.manage');
        $pdo = Container::getInstance()->get('db')->pdo();
        $roles = $pdo->query('SELECT id, name, code, scope FROM roles ORDER BY scope ASC, name ASC')->fetchAll(\PDO::FETCH_ASSOC);
        $permissions = $pdo->query('SELECT id, module_name, code FROM permissions ORDER BY module_name ASC, code ASC')->fetchAll(\PDO::FETCH_ASSOC);
        $assignments = $pdo->query('SELECT role_id, permission_id, effect FROM role_permissions ORDER BY role_id ASC')->fetchAll(\PDO::FETCH_ASSOC);

        view('super-admin/settings/index', [
            'title' => 'Parametrage plateforme',
            'settings' => Container::getInstance()->get('platformSettings')->listSystemSettings(),
            'subscription_plans' => Container::getInstance()->get('platformSettings')->listSubscriptionPlans(),
            'visual_defaults' => Container::getInstance()->get('platformSettings')->visualDefaults(),
            'roles' => $roles,
            'permissions' => $permissions,
            'assignments' => $assignments,
            'flash_success' => flash('success'),
        ]);

        audit_access('settings', null, 'screens', 'platform-settings', 'Consultation parametrage plateforme');
    }

    public function update(Request $request): void
    {
        authorize_access('platform.settings.manage');

        Container::getInstance()->get('platformSettings')->updateSystemSettings($request->request, current_user());
        flash('success', 'Parametrage plateforme mis a jour.');
        redirect('/super-admin/settings');
    }
}
