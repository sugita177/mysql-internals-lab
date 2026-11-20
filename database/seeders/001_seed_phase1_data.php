<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

return static function (): void {
    $now = date('Y-m-d H:i:s');

    // ギャップ（id: 11~19, 21~29, ...）を意図的に作るため 10 刻みで投入
    $rows = [
        [
            'id'          => 10,
            'sku'         => 'SKU-010',
            'category_id' => 10,
            'name'        => 'Mechanical Keyboard',
            'stock'       => 50,
            'price'       => 15000,
            'created_at'  => $now,
            'updated_at'  => $now,
        ],
        [
            'id'          => 20,
            'sku'         => 'SKU-020',
            'category_id' => 20,
            'name'        => 'Ergonomic Mouse',
            'stock'       => 30,
            'price'       => 8000,
            'created_at'  => $now,
            'updated_at'  => $now,
        ],
        [
            'id'          => 30,
            'sku'         => 'SKU-030',
            'category_id' => 20,
            'name'        => 'USB-C Hub',
            'stock'       => 100,
            'price'       => 4500,
            'created_at'  => $now,
            'updated_at'  => $now,
        ],
        [
            'id'          => 40,
            'sku'         => 'SKU-040',
            'category_id' => 30,
            'name'        => '4K Monitor',
            'stock'       => 15,
            'price'       => 52000,
            'created_at'  => $now,
            'updated_at'  => $now,
        ],
        [
            'id'          => 50,
            'sku'         => 'SKU-050',
            'category_id' => 40,
            'name'        => 'Desk Lamp',
            'stock'       => 40,
            'price'       => 6000,
            'created_at'  => $now,
            'updated_at'  => $now,
        ],
    ];

    foreach ($rows as $row) {
        Capsule::table('products')->updateOrInsert(['id' => $row['id']], $row);
    }
};
