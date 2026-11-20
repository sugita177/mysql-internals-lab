<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule();

$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => getenv('DB_HOST') ?: 'mysql',
    'port'      => getenv('DB_PORT') ?: '3306',
    'database'  => getenv('DB_DATABASE') ?: 'internals_lab',
    'username'  => getenv('DB_USERNAME') ?: 'lab_user',
    'password'  => getenv('DB_PASSWORD') ?: 'lab_secret',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_0900_ai_ci',
    'prefix'    => '',
    'strict'    => true,
    'engine'    => 'InnoDB',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

return $capsule;
