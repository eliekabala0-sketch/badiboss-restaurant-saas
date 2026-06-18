<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class UserAdminService
{
    private array $userColumnCache = [];

    public function __construct(private readonly Database $database)
    {
    }

    public function listUsers(?int $restaurantId = null): array
    {
        return $this->listUsersPage($restaurantId, ['per_page' => 500, 'allow_large_page' => true])['items'];
    }

    public function listUsersPage(?int $restaurantId = null, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $maxPerPage = !empty($filters['allow_large_page']) ? 500 : 50;
        $perPage = min($maxPerPage, max(10, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $where = [];
        $params = [];

        if ($restaurantId !== null) {
            $where[] = 'u.restaurant_id = :restaurant_id';
            $params['restaurant_id'] = $restaurantId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.full_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = $this->normalizeStatus($status);
        }

        $roleId = (int) ($filters['role_id'] ?? 0);
        if ($roleId > 0) {
            $where[] = 'u.role_id = :role_id';
            $params['role_id'] = $roleId;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $optionalSelects = $this->userOptionalSelects();

        $sql = 'SELECT u.id, u.restaurant_id, u.role_id, u.full_name, u.email, u.phone, u.status, u.must_change_password,
                       u.last_login_at, u.banned_at, u.archived_at' . $optionalSelects . ',
                       r.name AS role_name, r.code AS role_code, t.name AS restaurant_name, t.slug AS restaurant_slug
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                LEFT JOIN restaurants t ON t.id = u.restaurant_id'
                . $whereSql
                . ' ORDER BY u.id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->database->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $users = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            $user['role_display_name'] = restaurant_role_label((string) ($user['role_code'] ?? ''));
        }
        unset($user);

        $countStatement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM users u' . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        return [
            'items' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'role_id' => $roleId,
            ],
        ];
    }

    public function createUser(array $payload, array $actor): void
    {
        $restaurantId = $this->normalizeRestaurantId($payload['restaurant_id'] ?? null);
        $role = Container::getInstance()->get('roleAdmin')->assertAssignableRoleForRestaurant((int) $payload['role_id'], $restaurantId);

        $status = $this->normalizeStatus((string) ($payload['status'] ?? 'active'));
        $columns = ['restaurant_id', 'role_id', 'full_name', 'email', 'phone', 'password_hash', 'status', 'must_change_password', 'created_at', 'updated_at'];
        $values = [':restaurant_id', ':role_id', ':full_name', ':email', ':phone', ':password_hash', ':status', ':must_change_password', 'NOW()', 'NOW()'];
        $params = [
            'restaurant_id' => $restaurantId,
            'role_id' => (int) $role['id'],
            'full_name' => trim((string) $payload['full_name']),
            'email' => trim((string) $payload['email']),
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'password_hash' => password_hash((string) $payload['password'], PASSWORD_BCRYPT),
            'status' => $status,
            'must_change_password' => isset($payload['must_change_password']) ? 1 : 0,
        ];

        if ($this->userColumnExists('status_reason')) {
            $columns[] = 'status_reason';
            $values[] = ':status_reason';
            $params['status_reason'] = trim((string) ($payload['status_reason'] ?? '')) ?: null;
        }
        if ($status === 'disabled' && $this->userColumnExists('disabled_at')) {
            $columns[] = 'disabled_at';
            $values[] = 'NOW()';
        }
        if ($status === 'banned') {
            $columns[] = 'banned_at';
            $values[] = 'NOW()';
        }
        if ($status === 'archived') {
            $columns[] = 'archived_at';
            $values[] = 'NOW()';
            if ($this->userColumnExists('deleted_at')) {
                $columns[] = 'deleted_at';
                $values[] = 'NOW()';
            }
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
        );

        $statement->execute($params);

        $userId = (int) $this->database->pdo()->lastInsertId();
        $roleCode = (string) ($role['code'] ?? '');

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'users',
            'action_name' => 'user_created',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'new_values' => [
                'full_name' => $payload['full_name'],
                'email' => $payload['email'],
                'restaurant_id' => $restaurantId,
                'role_id' => $role['id'],
                'role_code' => $roleCode,
                'status' => $status,
            ],
            'justification' => 'Administrative user creation',
        ]);
    }

    public function updateUser(int $userId, array $payload, array $actor): void
    {
        $current = $this->findUser($userId);
        if ($current === null) {
            return;
        }

        $restaurantId = $this->normalizeRestaurantId($payload['restaurant_id'] ?? null);
        $role = Container::getInstance()->get('roleAdmin')->assertAssignableRoleForRestaurant((int) $payload['role_id'], $restaurantId);

        $statement = $this->database->pdo()->prepare(
            'UPDATE users
             SET restaurant_id = :restaurant_id,
                 role_id = :role_id,
                 full_name = :full_name,
                 email = :email,
                 phone = :phone,
                 must_change_password = :must_change_password,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'restaurant_id' => $restaurantId,
            'role_id' => (int) $role['id'],
            'full_name' => trim((string) $payload['full_name']),
            'email' => trim((string) $payload['email']),
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'must_change_password' => isset($payload['must_change_password']) ? 1 : 0,
        ]);

        if (!empty($payload['password'])) {
            $passwordStatement = $this->database->pdo()->prepare(
                'UPDATE users SET password_hash = :password_hash, must_change_password = 1, updated_at = NOW() WHERE id = :id'
            );
            $passwordStatement->execute([
                'id' => $userId,
                'password_hash' => password_hash((string) $payload['password'], PASSWORD_BCRYPT),
            ]);
        }

        $action = ((int) $current['role_id'] !== (int) $role['id']) ? 'user_role_changed' : 'user_updated';

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'users',
            'action_name' => $action,
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'old_values' => $this->redactSensitiveUserValues($current),
            'new_values' => $this->redactSensitiveUserValues(array_merge($payload, [
                'restaurant_id' => $restaurantId,
                'role_id' => (int) $role['id'],
                'role_code' => (string) ($role['code'] ?? ''),
            ])),
            'justification' => 'Administrative user update',
        ]);

        if ($action === 'user_role_changed') {
            $this->notifyTargetUser(
                $restaurantId,
                $userId,
                $actor,
                'personnel.role_changed',
                'warning',
                'Fonction modifiee',
                'Votre fonction a ete modifiee. Vous etes desormais ' . restaurant_role_label((string) ($role['code'] ?? '')) . '. Cliquez sur OK pour continuer.',
                'personnel:role:' . $userId . ':' . (int) $role['id'] . ':' . time(),
                null
            );
        }
    }

    public function changeStatus(int $userId, string $status, array $actor): void
    {
        $this->changeStatusWithReason($userId, $status, '', $actor);
    }

    public function changeStatusWithReason(int $userId, string $status, string $reason, array $actor): void
    {
        $current = $this->findUser($userId);
        if ($current === null) {
            return;
        }

        $normalizedStatus = $this->normalizeStatus($status);
        if ((int) ($actor['id'] ?? 0) === $userId && $normalizedStatus !== 'active') {
            throw new \RuntimeException('Vous ne pouvez pas bloquer votre propre compte.');
        }

        $assignments = ['status = :status', 'updated_at = NOW()'];
        $params = [
            'id' => $userId,
            'status' => $normalizedStatus,
        ];

        if ($this->userColumnExists('status_reason')) {
            $assignments[] = 'status_reason = :status_reason';
            $params['status_reason'] = trim($reason) !== '' ? trim($reason) : null;
        }
        if ($this->userColumnExists('disabled_at')) {
            $assignments[] = $normalizedStatus === 'disabled'
                ? 'disabled_at = COALESCE(disabled_at, NOW())'
                : 'disabled_at = NULL';
        }
        if ($this->userColumnExists('suspended_at')) {
            $assignments[] = $normalizedStatus === 'disabled'
                ? 'suspended_at = COALESCE(suspended_at, NOW())'
                : 'suspended_at = NULL';
        }
        $assignments[] = $normalizedStatus === 'banned'
            ? 'banned_at = COALESCE(banned_at, NOW())'
            : 'banned_at = NULL';
        $assignments[] = $normalizedStatus === 'archived'
            ? 'archived_at = COALESCE(archived_at, NOW())'
            : ($normalizedStatus === 'active' ? 'archived_at = NULL' : 'archived_at = archived_at');
        if ($this->userColumnExists('deleted_at')) {
            $assignments[] = $normalizedStatus === 'archived'
                ? 'deleted_at = NOW()'
                : ($normalizedStatus === 'active' ? 'deleted_at = NULL' : 'deleted_at = deleted_at');
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($params);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $current['restaurant_id'] !== null ? (int) $current['restaurant_id'] : null,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'users',
            'action_name' => 'user_status_changed',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'old_values' => ['status' => $current['status']],
            'new_values' => ['status' => $normalizedStatus, 'reason' => trim($reason)],
            'justification' => 'Administrative user status change',
        ]);

        $this->notifyStatusChange($current, $normalizedStatus, trim($reason), $actor);
    }

    public function updateRestaurantUser(int $userId, int $restaurantId, array $payload, array $actor): void
    {
        $current = $this->findUser($userId);
        if ($current === null || (int) ($current['restaurant_id'] ?? 0) !== $restaurantId) {
            throw new \RuntimeException('Utilisateur introuvable pour ce restaurant.');
        }

        $payload['restaurant_id'] = (string) $restaurantId;
        $this->updateUser($userId, $payload, $actor);
    }

    public function registerPublicCustomer(array $restaurant, array $payload): int
    {
        $restaurantId = (int) ($restaurant['id'] ?? 0);
        if ($restaurantId <= 0) {
            throw new \RuntimeException('Restaurant introuvable.');
        }

        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($fullName === '' || $email === '' || $password === '') {
            throw new \RuntimeException('Nom, e-mail et mot de passe sont obligatoires.');
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO users
            (restaurant_id, role_id, full_name, email, phone, password_hash, status, must_change_password, created_at, updated_at)
             VALUES
            (:restaurant_id, :role_id, :full_name, :email, :phone, :password_hash, "active", 0, NOW(), NOW())'
        );

        $statement->execute([
            'restaurant_id' => $restaurantId,
            'role_id' => Container::getInstance()->get('roleAdmin')->customerRoleId(),
            'full_name' => $fullName,
            'email' => $email,
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        $userId = (int) $this->database->pdo()->lastInsertId();

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => null,
            'actor_name' => 'Portail public client',
            'actor_role_code' => 'customer',
            'module_name' => 'users',
            'action_name' => 'public_customer_registered',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'new_values' => [
                'restaurant_id' => $restaurantId,
                'email' => $email,
                'role_code' => 'customer',
            ],
            'justification' => 'Inscription client liee au restaurant',
        ]);

        return $userId;
    }

    public function findUser(int $userId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.*, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    private function normalizeStatus(string $status): string
    {
        $status = trim($status);
        if ($status === 'inactive' || $status === 'suspended') {
            $status = 'disabled';
        }

        return in_array($status, ['active', 'disabled', 'banned', 'archived'], true) ? $status : 'active';
    }

    private function redactSensitiveUserValues(array $values): array
    {
        foreach (['password', 'password_hash'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }

    private function userOptionalSelects(): string
    {
        $selects = [];
        foreach (['disabled_at', 'suspended_at', 'deleted_at', 'status_reason'] as $column) {
            $selects[] = $this->userColumnExists($column) ? 'u.' . $column : 'NULL AS ' . $column;
        }

        return ', ' . implode(', ', $selects);
    }

    private function notifyStatusChange(array $current, string $newStatus, string $reason, array $actor): void
    {
        $restaurantId = (int) ($current['restaurant_id'] ?? 0);
        $userId = (int) ($current['id'] ?? 0);
        if ($restaurantId <= 0 || $userId <= 0) {
            return;
        }

        $actorLabel = named_actor_label($actor['full_name'] ?? null, $actor['role_code'] ?? null);
        $reasonText = $reason !== '' ? ' Motif : ' . $reason . '.' : '';
        $title = 'Statut du compte modifie';
        $level = 'warning';
        $message = 'Votre statut de compte a ete modifie par ' . $actorLabel . '.' . $reasonText;

        if ($newStatus === 'banned') {
            $title = 'Connexion bloquee';
            $level = 'danger';
            $message = 'Votre connexion a ete bloquee par ' . $actorLabel . '.' . $reasonText;
        } elseif ($newStatus === 'disabled') {
            $title = 'Compte suspendu';
            $level = 'danger';
            $message = 'Vous etes suspendu par ' . $actorLabel . '.' . $reasonText . ' Veuillez voir votre chef hierarchique.';
        } elseif ($newStatus === 'active') {
            $title = 'Compte reactive';
            $level = 'success';
            $message = 'Votre compte a ete reactive par ' . $actorLabel . '.';
        } elseif ($newStatus === 'archived') {
            $title = 'Compte archive';
            $level = 'danger';
            $message = 'Votre compte a ete archive par ' . $actorLabel . '.' . $reasonText;
        }

        $this->notifyTargetUser(
            $restaurantId,
            $userId,
            $actor,
            'personnel.status_changed',
            $level,
            $title,
            $message,
            'personnel:status:' . $userId . ':' . $newStatus . ':' . time(),
            null
        );
    }

    private function notifyTargetUser(
        int $restaurantId,
        int $userId,
        array $actor,
        string $eventCode,
        string $level,
        string $title,
        string $message,
        string $eventKey,
        ?string $targetUrl
    ): void {
        try {
            Container::getInstance()->get('uiNotifications')->queueForUsers(
                $restaurantId,
                [$userId],
                $actor,
                $eventCode,
                $level,
                $title,
                $message,
                $targetUrl,
                $eventKey,
                null,
                720
            );
        } catch (\Throwable $exception) {
            error_log('[personnel_notification] ' . $exception->getMessage());
        }
    }

    private function userColumnExists(string $column): bool
    {
        if (!array_key_exists($column, $this->userColumnCache)) {
            $statement = $this->database->pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = "users" AND column_name = :column'
            );
            $statement->execute(['column' => $column]);
            $this->userColumnCache[$column] = (int) $statement->fetchColumn() > 0;
        }

        return $this->userColumnCache[$column];
    }

    private function normalizeRestaurantId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $restaurantId = (int) $value;

        return $restaurantId > 0 ? $restaurantId : null;
    }
}
