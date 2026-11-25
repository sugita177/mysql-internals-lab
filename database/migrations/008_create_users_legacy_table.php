<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('users_legacy')) {
            return;
        }

        Capsule::schema()->create('users_legacy', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 20)->index();        // 文字列型（数値検索で暗黙型変換を検証）
            $table->string('email', 100)->index();       // LIKE 前方一致 vs 中間一致を検証
            $table->tinyInteger('is_active')->index();   // 低カーディナリティ（95% が 1、5% が 0）
            $table->dateTime('registered_at')->index();  // 関数適用 DATE() vs 範囲検索を検証
        });
    },
];
