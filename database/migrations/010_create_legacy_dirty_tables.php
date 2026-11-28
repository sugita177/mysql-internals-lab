<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (!Capsule::schema()->hasTable('legacy_customers')) {
            Capsule::schema()->create('legacy_customers', function (Blueprint $table): void {
                $table->id();
                // 意図的に UNIQUE 制約を張らない（レガシー環境の重複登録バグを再現）
                $table->string('email', 100)->index();
                $table->string('name', 50);
                $table->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('legacy_orders')) {
            Capsule::schema()->create('legacy_orders', function (Blueprint $table): void {
                $table->id();
                // 意図的に FOREIGN KEY 制約を張らない（孤児レコード発生の再現）
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedInteger('amount');
                $table->timestamps();
            });
        }
    },
];
