<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('order_items')) {
            return;
        }

        Capsule::schema()->create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // 外部キー制約: 子テーブルへの INSERT/UPDATE 時に親テーブル (products) へ S ロックが波及する
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');
        });
    },
];
