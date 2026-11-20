<?php

declare(strict_types=1);

/**
 * Phase 1 演習: Session B（ロックの観測 & 競合テスト側）
 *
 * 使い方:
 *   Session A が待機中の状態で、別ターミナルから実行する:
 *   docker compose exec php php exercises/phase1/01_lock_scope_session_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Session B (Observer & Contender) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

// ------------------------------------------------------------
// Part 1: performance_schema.data_locks で現在のロックを観測する
// ------------------------------------------------------------
echo "--- Current InnoDB Locks (performance_schema.data_locks) ---\n";

// TODO 1: performance_schema.data_locks から現在のロック一覧を取得する SQL を書こう
// 取得したいカラム例:
//   ENGINE_TRANSACTION_ID, OBJECT_NAME, INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_STATUS, LOCK_DATA
// ヒント: OBJECT_SCHEMA = 'internals_lab' で絞り込むと見やすい
$locks = $conn->select(
            "SELECT ENGINE_TRANSACTION_ID, OBJECT_NAME, INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_STATUS, LOCK_DATA 
            FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = 'internals_lab'"
        ); // <-- $conn->select("...") に書き換える

if ($locks === []) {
    echo "(ロックが観測されませんでした。Session A が待機中か、TODO 1 が未実装です)\n";
} else {
    foreach ($locks as $lock) {
        printf(
            "trx=%s | table=%s | index=%-15s | type=%-6s | mode=%-18s | status=%-7s | data=%s\n",
            $lock->ENGINE_TRANSACTION_ID ?? '-',
            $lock->OBJECT_NAME ?? '-',
            $lock->INDEX_NAME ?? 'NULL',
            $lock->LOCK_TYPE ?? '-',
            $lock->LOCK_MODE ?? '-',
            $lock->LOCK_STATUS ?? '-',
            $lock->LOCK_DATA ?? 'NULL'
        );
    }
}

echo "\n";

// ------------------------------------------------------------
// Part 2: 実際に別トランザクションから INSERT / UPDATE を試してブロック有無を検証する
// ------------------------------------------------------------
// TODO 2: Part 1 でロック範囲を観測できたら、以下のコメントを外して
//         「どの ID への INSERT や UPDATE がブロックされるか（あるいは通るか）」を検証しよう。
try {
    $conn->beginTransaction();

    // 例: ギャップへの INSERT を試す（id = 25 はブロックされる？通る？）
    // $conn->table('products')->insert([
    //     'id'          => 25,
    //     'sku'         => 'SKU-025',
    //     'category_id' => 20,
    //     'name'        => 'Gap Test Item',
    //     'stock'       => 10,
    //     'price'       => 1000,
    //     'created_at'  => date('Y-m-d H:i:s'),
    //     'updated_at'  => date('Y-m-d H:i:s'),
    // ]);
    $conn->table('products')->where('id', 20)->update(['stock' => 99]);

    echo "[Session B] クエリ成功！（ブロックされませんでした）\n";
    $conn->rollBack();
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    echo "[Session B] ブロック/エラー発生: {$e->getMessage()}\n";
}
