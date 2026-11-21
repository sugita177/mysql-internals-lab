<?php

declare(strict_types=1);

/**
 * Phase 2-2 演習: Session A（ギャップロック起因のデッドロック：Insert Intention Lock）
 *
 * シナリオ:
 *   「id = 25 が無ければ INSERT する」処理（Check-Then-Act）を 2 セッション同時実行する。
 *
 * 使い方:
 *   docker compose exec php php exercises/phase2/02_deadlock_gap_a.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 2-2 Session A (target id = 25) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: 存在しない id = 25 を lockForUpdate() で検索する（ここでギャップロック取得）
    Product::where('id', 25)->lockForUpdate()->first();

    echo "[Session A] id = 25 を FOR UPDATE で確認しました（存在なし → ギャップロック取得）。\n";
    echo ">>> 別ターミナルで Session B を起動して id = 26 のギャップロックを取らせたら、Enter キーを押してください: ";
    fgets(STDIN);

    echo "[Session A] id = 25 を INSERT します（Insert Intention Lock を要求）...\n";

    // TODO 3: id = 25, sku = 'SKU-025' などを products テーブルへ INSERT する
    // ヒント: Capsule::table('products')->insert([...]);
    Capsule::table('products')->insert(
        [
            'id'          => 25,
            'sku'         => 'SKU-025',
            'category_id' => 20,
            'name'        => 'Test Product A',
            'stock'       => 30,
            'price'       => 8000,
        ]
    );

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
