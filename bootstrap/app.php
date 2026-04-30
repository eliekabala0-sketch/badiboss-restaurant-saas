<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Container;
use App\Core\Router;

$config = [
    'app' => require BASE_PATH . '/config/app.php',
    'database' => require BASE_PATH . '/config/database.php',
];

$container = Container::getInstance();
$container->set('config', $config);

$router = new Router();
require BASE_PATH . '/routes/web.php';
require BASE_PATH . '/routes/api.php';

return new App($config, $router);
