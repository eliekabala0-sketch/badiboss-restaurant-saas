<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class MenuAdminController
{
    public function superAdminIndex(Request $request): void
    {
        authorize_access('platform.restaurants.manage');
        $restaurantId = (int) ($request->query['restaurant_id'] ?? 0);
        $restaurantService = Container::getInstance()->get('restaurantAdmin');
        $restaurant = $restaurantId > 0 ? $restaurantService->findRestaurant($restaurantId) : null;

        view('super-admin/menu/index', [
            'title' => 'Menu restaurants',
            'restaurants' => $restaurantService->listRestaurants(),
            'restaurant' => $restaurant,
            'categories' => $restaurant ? Container::getInstance()->get('menuAdmin')->listCategories($restaurantId) : [],
            'items' => $restaurant ? Container::getInstance()->get('menuAdmin')->listItems($restaurantId) : [],
            'flash_success' => flash('success'),
        ]);

        audit_access('menu', $restaurantId > 0 ? $restaurantId : null, 'screens', 'super-admin-menu', 'Consultation menu super administrateur');
    }

    public function ownerIndex(Request $request): void
    {
        authorize_access('menu.view');
        $restaurantId = current_restaurant_id();

        view('owner/menu', [
            'title' => 'Menu restaurant',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'categories' => Container::getInstance()->get('menuAdmin')->listCategories($restaurantId),
            'items' => Container::getInstance()->get('menuAdmin')->listItems($restaurantId),
            'menu_audits' => Container::getInstance()->get('menuAdmin')->recentAudits($restaurantId, 12),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('menu', $restaurantId, 'screens', 'owner-menu', 'Consultation menu restaurant');
    }

    public function storeCategory(Request $request): void
    {
        $restaurantId = (int) $request->input('restaurant_id');
        Container::getInstance()->get('menuAdmin')->createCategory($restaurantId, $this->categoryPayload($request), $_SESSION['user']);

        flash('success', 'La categorie du menu a ete creee.');
        redirect('/super-admin/menu?restaurant_id=' . $restaurantId);
    }

    public function storeOwnerCategory(Request $request): void
    {
        authorize_access('menu.view');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('menuAdmin')->createCategory($restaurantId, $this->categoryPayload($request), $_SESSION['user']);

        flash('success', 'La categorie du menu a ete creee.');
        redirect('/owner/menu');
    }

    public function storeItem(Request $request): void
    {
        $restaurantId = (int) $request->input('restaurant_id');
        Container::getInstance()->get('menuAdmin')->createItem($restaurantId, $this->itemPayload($request), $_SESSION['user']);

        flash('success', 'Le plat du menu a ete cree.');
        redirect('/super-admin/menu?restaurant_id=' . $restaurantId);
    }

    public function storeOwnerItem(Request $request): void
    {
        authorize_access('menu.view');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('menuAdmin')->createItem($restaurantId, $this->itemPayload($request), $_SESSION['user']);

        flash('success', 'Le plat du menu a ete cree.');
        redirect('/owner/menu');
    }

    public function updateCategory(Request $request): void
    {
        $categoryId = (int) $request->route('id');
        Container::getInstance()->get('menuAdmin')->updateCategory($categoryId, $this->categoryPayload($request), $_SESSION['user']);

        flash('success', 'La categorie du menu a ete mise a jour.');
        redirect('/super-admin/menu?restaurant_id=' . (int) $request->input('restaurant_id'));
    }

    public function updateItem(Request $request): void
    {
        $itemId = (int) $request->route('id');
        Container::getInstance()->get('menuAdmin')->updateItem($itemId, $this->itemPayload($request), $_SESSION['user']);

        flash('success', 'Le plat du menu a ete mis a jour.');
        redirect('/super-admin/menu?restaurant_id=' . (int) $request->input('restaurant_id'));
    }

    public function updateOwnerItem(Request $request): void
    {
        authorize_access('menu.item.edit');

        $itemId = (int) $request->route('id');
        Container::getInstance()->get('menuAdmin')->updateItem($itemId, $this->itemPayload($request), $_SESSION['user']);

        flash('success', 'Le plat du menu a ete modifie sans toucher aux anciennes ventes.');
        redirect('/owner/menu');
    }

    public function markStatus(Request $request): void
    {
        $this->authorizeTenantMenuStatus();
        $itemId = (int) $request->route('id');
        Container::getInstance()->get('menuAdmin')->markItemStatus($itemId, (string) $request->input('status', 'active'), $_SESSION['user']);

        $back = current_user()['scope'] === 'super_admin'
            ? '/super-admin/menu?restaurant_id=' . (int) $request->input('restaurant_id')
            : '/owner/menu';

        flash('success', 'Le statut du plat a ete mis a jour.');
        redirect($back);
    }

    private function categoryPayload(Request $request): array
    {
        $name = trim((string) $request->input('name'));

        return [
            'name' => $name,
            'slug' => $this->normalizeSlug((string) $request->input('slug', $name)),
            'description' => $request->input('description'),
            'display_order' => $request->input('display_order', 0),
            'status' => $request->input('status', 'active'),
        ];
    }

    private function itemPayload(Request $request): array
    {
        $name = trim((string) $request->input('name'));

        return [
            'category_id' => (int) $request->input('category_id'),
            'name' => $name,
            'slug' => $this->normalizeSlug((string) $request->input('slug', $name)),
            'description' => (string) $request->input('description'),
            'image_url' => (string) $request->input('image_url'),
            'price' => (string) $request->input('price'),
            'status' => (string) $request->input('status', 'active'),
            'is_available' => $request->input('is_available'),
            'display_order' => (string) $request->input('display_order', '0'),
            'available_dine_in' => $request->input('available_dine_in'),
            'available_takeaway' => $request->input('available_takeaway'),
            'available_delivery' => $request->input('available_delivery'),
        ];
    }

    private function authorizeTenantMenuStatus(): void
    {
        $user = current_user();

        if (($user['scope'] ?? null) === 'super_admin') {
            authorize_access('platform.restaurants.manage');
            return;
        }

        authorize_access('menu.view');

        if (!in_array((string) ($user['role_code'] ?? ''), ['owner', 'manager'], true)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
    }

    private function normalizeSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'article-menu';
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'article-menu';
    }
}
