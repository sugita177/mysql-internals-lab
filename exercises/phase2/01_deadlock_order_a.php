<?php

declare(strict_types=1);

/**
 * Phase 2-1 演習: Session A（id = 10 → id = 20 の順でロックを取得）
 *
 * 使い方:
 *   docker compose exec php php exercises/phase2/01_deadlock_order_a.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 2-1 Session A (id=10 -> id=20) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: まず id = 10 のレコードに排他ロック（FOR UPDATE）を取得する
    // ヒント: Product::where(...)->...->first();
    Product::where('id', 10)->lockForUpdate()->first();

    echo "[Session A] id = 10 のロックを取得しました。\n";
    echo ">>> 別ターミナルで Session B を起動して id = 20 をロックさせたら、Enter キーを押してください: ";
    fgets(STDIN);

    echo "[Session A] 次に id = 20 のロックを取得しにいきます...\n";

    // TODO 3: 次に id = 20 のレコードに排他ロック（FOR UPDATE）を取得し、stock を更新する
    Product::where('id', 20)->lockForUpdate()->first();

    // TODO 4: コミットする
    $conn->rollBack();
    echo "[Session A] コミット完了！\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Session A] Error ({$e->getCode()}): {$e->getMessage()}\n");
    exit(1);
}
