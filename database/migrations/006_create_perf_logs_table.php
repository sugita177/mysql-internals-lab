<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('perf_logs')) {
            return;
        }

        Capsule::schema()->create('perf_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('category')->index();
            $table->string('payload', 120);
            $table->timestamp('created_at')->nullable();
        });
    },
];
