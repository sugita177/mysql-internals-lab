<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('products')) {
            return;
        }

        Capsule::schema()->create('products', function (Blueprint $table): void {
            // 主キー（クラスタードインデックス）
            // ギャップロックを観察しやすくするため、意図的に非連続なID（10, 20, 30...）を手動投入する
            $table->unsignedBigInteger('id')->primary();

            // 一意セカンダリインデックス
            $table->string('sku', 32)->unique();

            // 非一意セカンダリインデックス
            $table->unsignedInteger('category_id')->index();

            // インデックスなしカラム（フルスキャン時の全行ロック再現用）
            $table->string('name', 100);

            $table->integer('stock')->default(0);
            $table->unsignedInteger('price')->default(0);
            $table->timestamps();
        });
    },
];
