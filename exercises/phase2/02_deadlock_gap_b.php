<?php

declare(strict_types=1);

/**
 * Phase 2-2 演習: Session B（ギャップロック起因のデッドロック：Insert Intention Lock）
 *
 * 使い方:
 *   Session A が待機中に、別ターミナルから実行する:
 *   docker compose exec php php exercises/phase2/02_deadlock_gap_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 2-2 Session B (target id = 26) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: 存在しない id = 26 を lockForUpdate() で検索する
    // 注目: id = 25 とは別の ID なのに、Session A にブロックされずにここを通過する！
    Product::where('id', 26)->lockForUpdate()->first();

    echo "[Session B] id = 26 を FOR UPDATE で確認しました（ブロックされずに通過！）。\n";
    echo ">>> Session A で Enter を押したあと、Enter キーを押して id = 26 を INSERT してください: ";
    fgets(STDIN);

    echo "[Session B] id = 26 を INSERT します...\n";

    // TODO 3: id = 26, sku = 'SKU-026' などを products テーブルへ INSERT する
    Capsule::table('products')->insert(
        [
            'id'          => 26,
            'sku'         => 'SKU-026',
            'category_id' => 20,
            'name'        => 'Test Product B',
            'stock'       => 30,
            'price'       => 8000,
        ]
    );

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
