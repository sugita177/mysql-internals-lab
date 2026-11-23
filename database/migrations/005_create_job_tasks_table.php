<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('job_tasks')) {
            return;
        }

        Capsule::schema()->create('job_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('payload', 100);
            $table->string('status', 20)->default('pending')->index();
            $table->string('worker', 32)->nullable();
            $table->timestamps();
        });
    },
];
