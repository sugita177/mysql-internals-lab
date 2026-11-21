<?php

declare(strict_types=1);

/**
 * Phase 2-3 演習: Session A（外部キー制約による親テーブルへの S ロック波及 → デッドロック）
 *
 * シナリオ:
 *   「注文明細（order_items）を INSERT してから、商品（products）の在庫を減らす」
 *   というよくある注文処理を 2 セッション同時実行する。
 *
 * 使い方:
 *   docker compose exec php php exercises/phase2/03_deadlock_fk_a.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 2-3 Session A (FK S-Lock -> X-Lock Upgrade) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: order_items テーブルに product_id = 10, quantity = 1 を INSERT する
    // 注目: この瞬間、外部キーチェックのため親テーブル products (id = 10) に暗黙の S ロックが張られる！
    Capsule::table('order_items')->insert([
        'product_id' => 10,
        'quantity'   => 1,
    ]);
    

    echo "[Session A] order_items に INSERT しました（products.id=10 に S ロック取得済み）。\n";
    echo ">>> 別ターミナルで Session B を起動して同じ手順で INSERT させたら、Enter キーを押してください: ";
    fgets(STDIN);

    echo "[Session A] products.id=10 の在庫を減らします（S → X ロックへ昇格要求）...\n";

    // TODO 3: products テーブルの id = 10 の stock を 1 減らす UPDATE を実行する
    Product::where('id', 10)->decrement('stock', 1);

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
