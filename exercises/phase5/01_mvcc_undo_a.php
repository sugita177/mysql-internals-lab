<?php

declare(strict_types=1);

/**
 * Phase 5-1 演習: Session A（MVCC Read View 保持側）
 *
 * 使い方:
 *   docker compose exec php php exercises/phase5/01_mvcc_undo_a.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

echo "=== Phase 5-1 Session A (MVCC Snapshot Read) ===\n";

try {
    // TODO 1: トランザクションを開始し、通常の SELECT（ロックなし）で
    //         products.id = 10 の stock を取得して表示しよう
    //         （この最初の SELECT の瞬間に MVCC の Read View が生成される！）
    $conn->beginTransaction();
    $initialStock = $conn->table('products')->where('id', 10)->select('stock')->first();
    echo "[Session A] products.id = 10 の stock: {$initialStock->stock}\n";

    echo ">>> 別ターミナルで Session B を実行したあと、Enter キーを押してください: ";
    fgets(STDIN);

    // TODO 2: Session B が 100 回更新・コミットしたあとに、同じトランザクション内で以下を比較しよう
    //   1) 通常の SELECT（スナップショット読み: Consistent Read）で stock を取得して表示
    //   2) lockForUpdate() 付き SELECT（現在読み取り: Current Read）で stock を取得して表示
    $consistentReadStock = $conn->table('products')->where('id', 10)->select('stock')->first();
    echo "[Session A] Consistent Read stock: {$consistentReadStock->stock}\n";

    $currentReadStock = $conn->table('products')->where('id', 10)->select('stock')->lockForUpdate()->first();
    echo "[Session A] Current Read stock: {$currentReadStock->stock}\n";

    // TODO 3: ロールバックして Read View を解放しよう
    $conn->rollBack();
    echo "[Session A] トランザクションを終了しました（これで Undo ログがパージ可能になります）。\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Session A] Error: {$e->getMessage()}\n");
    exit(1);
}
