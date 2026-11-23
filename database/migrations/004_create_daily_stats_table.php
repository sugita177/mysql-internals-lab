<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('daily_stats')) {
            return;
        }

        Capsule::schema()->create('daily_stats', function (Blueprint $table): void {
            $table->id(); // AUTO_INCREMENT
            $table->string('metric_key', 64)->unique(); // 一意キー
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();
        });
    },
];
