<?php

declare(strict_types=1);

/**
 * Phase 2-1 演習: Session B（id = 20 → id = 10 の逆順でロックを取得）
 *
 * 使い方:
 *   Session A が id = 10 をロックして待機中に、別ターミナルから実行する:
 *   docker compose exec php php exercises/phase2/01_deadlock_order_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 2-1 Session B (id=20 -> id=10) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: Session A とは逆に、まず id = 20 のレコードに排他ロック（FOR UPDATE）を取得する
    Product::where('id', 20)->lockForUpdate()->first();

    echo "[Session B] id = 20 のロックを取得しました。\n";
    echo ">>> Session A で Enter を押す前（または後）に Enter を押して id = 10 を狙います: ";
    fgets(STDIN);

    echo "[Session B] 次に id = 10 のロックを取得しにいきます...\n";

    // TODO 3: 次に id = 10 のレコードに排他ロック（FOR UPDATE）を取得し、stock を更新する
    Product::where('id', 10)->lockForUpdate()->first();

    // TODO 4: コミットする
    $conn->rollBack();
    echo "[Session B] コミット完了！\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Session B] Error ({$e->getCode()}): {$e->getMessage()}\n");
    exit(1);
}
