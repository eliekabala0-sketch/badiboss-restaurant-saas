<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\SuperAdminTenantController;
use App\Middleware\ApiSuperAdminMiddleware;
use App\Middleware\ApiTokenMiddleware;

$router->get('/api/v1/health', [AuthApiController::class, 'health']);
$router->post('/api/v1/auth/login', [AuthApiController::class, 'login']);
$router->get('/api/v1/super-admin/restaurants', [SuperAdminTenantController::class, 'index'], [ApiTokenMiddleware::class, ApiSuperAdminMiddleware::class]);
$router->post('/api/v1/super-admin/restaurants', [SuperAdminTenantController::class, 'store'], [ApiTokenMiddleware::class, ApiSuperAdminMiddleware::class]);
