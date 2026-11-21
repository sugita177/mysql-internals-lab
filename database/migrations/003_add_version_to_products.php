<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasColumn('products', 'version')) {
            return;
        }

        Capsule::schema()->table('products', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('price');
        });
    },
];
