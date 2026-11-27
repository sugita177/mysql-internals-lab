<?php

declare(strict_types=1);

/**
 * Phase 5-3 演習: Session A（通常の SELECT だけで MDL を握り続ける側）
 *
 * 使い方:
 *   docker compose exec php php exercises/phase5/03_mdl_accident_a.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 5-3 Session A (Holds Metadata Lock via plain SELECT) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始し、ロックなしの通常の SELECT で
    //         products.id = 10 を 1 件取得しよう（これだけで SHARED_READ MDL が張られる！）
    $conn->beginTransaction();
    $conn->table('products')->where('id', 10)->first();

    echo "[Session A] 通常の SELECT を実行しました（トランザクション未終了）。\n";
    echo ">>> 別ターミナルで Session B (ALTER TABLE) を実行し、さらにターミナル3で\n";
    echo "    SHOW FULL PROCESSLIST を見てから Enter キーを押してください: ";
    fgets(STDIN);

    // TODO 2: トランザクションを終了して MDL を解放しよう
    $conn->rollBack();
    echo "[Session A] トランザクション終了（MDL 解放）。\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Session A] Error: {$e->getMessage()}\n");
    exit(1);
}
