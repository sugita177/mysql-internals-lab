<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (Capsule::schema()->hasTable('orders_perf')) {
            return;
        }

        Capsule::schema()->create('orders_perf', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20);
            $table->unsignedInteger('total_amount');
            $table->dateTime('ordered_at');

            // 比較用の「悪い順序」の複合インデックス（範囲条件が先頭にある）
            $table->index(['ordered_at', 'user_id', 'status'], 'idx_bad_order');

            // TODO: 以下のクエリを最速（Using filesort なし）にする複合インデックスを設計して定義しよう！
            //   SELECT ... FROM orders_perf
            //   WHERE user_id = ? AND status = ? AND ordered_at >= ?
            //   ORDER BY ordered_at DESC LIMIT 20
            // ヒント: $table->index([...], 'idx_good_order');
            $table->index(['user_id', 'status', 'ordered_at'], 'idx_good_order');
        });
    },
];
