<?php

declare(strict_types=1);

/**
 * Phase 2-3 演習: Session B（外部キー制約による親テーブルへの S ロック波及 → デッドロック）
 *
 * 使い方:
 *   Session A が待機中に、別ターミナルから実行する:
 *   docker compose exec php php exercises/phase2/03_deadlock_fk_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 2-3 Session B (FK S-Lock -> X-Lock Upgrade) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: order_items テーブルに product_id = 10, quantity = 2 を INSERT する
    // 注目: Session A と同じ親レコード (products.id = 10) だが、S ロック同士は両立するため通過する！
    Capsule::table('order_items')->insert([
        'product_id' => 10,
        'quantity' => 2,
    ]);

    echo "[Session B] order_items に INSERT しました（こちらも products.id=10 に S ロック取得！）。\n";
    echo ">>> Session A で Enter を押したあと、Enter キーを押して在庫を減らしてください: ";
    fgets(STDIN);

    echo "[Session B] products.id=10 の在庫を減らします（S → X ロックへ昇格要求）...\n";

    // TODO 3: products テーブルの id = 10 の stock を 2 減らす UPDATE を実行する
    Product::where('id', 10)->decrement('stock', 2);

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
