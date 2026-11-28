<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

return static function (): void {
    $now = date('Y-m-d H:i:s');

    Capsule::table('legacy_orders')->truncate();
    Capsule::table('legacy_customers')->truncate();

    Capsule::table('legacy_customers')->insert([
        ['id' => 1, 'email' => 'alice@example.com', 'name' => 'Alice (1st)', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'email' => 'alice@example.com', 'name' => 'Alice (dup)', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 3, 'email' => 'bob@example.com',   'name' => 'Bob (1st)',   'created_at' => $now, 'updated_at' => $now],
        ['id' => 4, 'email' => 'bob@example.com',   'name' => 'Bob (dup1)',  'created_at' => $now, 'updated_at' => $now],
        ['id' => 5, 'email' => 'bob@example.com',   'name' => 'Bob (dup2)',  'created_at' => $now, 'updated_at' => $now],
        ['id' => 6, 'email' => 'carol@example.com', 'name' => 'Carol',       'created_at' => $now, 'updated_at' => $now],
    ]);

    Capsule::table('legacy_orders')->insert([
        ['id' => 101, 'customer_id' => 1,   'amount' => 3000, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 102, 'customer_id' => 3,   'amount' => 5000, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 103, 'customer_id' => 6,   'amount' => 2000, 'created_at' => $now, 'updated_at' => $now],
        // 親が存在しない孤児レコード
        ['id' => 104, 'customer_id' => 999, 'amount' => 8000, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 105, 'customer_id' => 888, 'amount' => 1500, 'created_at' => $now, 'updated_at' => $now],
    ]);
};
