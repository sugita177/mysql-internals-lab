<?php

declare(strict_types=1);

/**
 * Phase 3-1 演習: Eloquent の lockForUpdate() / sharedLock() が発行する SQL とリレーションの罠
 *
 * 使い方:
 *   docker compose exec php php exercises/phase3/01_eloquent_lock_sql.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// テスト用の order_items が 0 件なら 1 件作成しておく
if (OrderItem::count() === 0) {
    OrderItem::create(['product_id' => 10, 'quantity' => 1]);
}

// ============================================================
// 実験 1: トランザクション外で lockForUpdate() を呼ぶとどうなるか？
// ============================================================
echo "=== 実験 1: トランザクション外での lockForUpdate() ===\n";

// TODO 1: トランザクションを開始せずに Product::where('id', 10)->lockForUpdate()->first() を実行し、
//         直後に performance_schema.data_locks を確認してみよう
Product::where('id', 10)->lockForUpdate()->first();

$locksExp1 = $conn->select(
    "SELECT OBJECT_NAME, INDEX_NAME, LOCK_MODE, LOCK_STATUS, LOCK_DATA
     FROM performance_schema.data_locks
     WHERE OBJECT_SCHEMA = 'internals_lab'"
);
echo '実験 1 後の残存ロック数: ' . count($locksExp1) . " 件\n\n";

// ============================================================
// 実験 2: with()（Eager Loading）と lockForUpdate() を併用したときの発行 SQL とロック対象
// ============================================================
echo "=== 実験 2: with('product')->lockForUpdate() の発行 SQL ===\n";

$conn->enableQueryLog();
$conn->flushQueryLog();

// TODO 2: トランザクションを開始し、OrderItem::with('product')->lockForUpdate()->get() を実行しよう
//         さらにその状態で performance_schema.data_locks を確認して、
//         「order_items」と「products」のどちらにロックが張られているか観測しよう
$conn->beginTransaction();
OrderItem::with('product')->lockForUpdate()->get();

$locksExp2 = $conn->select(
    "SELECT OBJECT_NAME, INDEX_NAME, LOCK_MODE, LOCK_STATUS, LOCK_DATA
     FROM performance_schema.data_locks
     WHERE OBJECT_SCHEMA = 'internals_lab'"
); // <-- TODO 2 で data_locks を取得する

foreach ($conn->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}
echo "\n--- 観測されたロック ---\n";
foreach ($locksExp2 as $lock) {
    printf(
        "table=%-12s | index=%-10s | mode=%-16s | data=%s\n",
        $lock->OBJECT_NAME,
        $lock->INDEX_NAME ?? 'NULL',
        $lock->LOCK_MODE,
        $lock->LOCK_DATA ?? 'NULL'
    );
}

if ($conn->transactionLevel() > 0) {
    $conn->rollBack();
}

// ============================================================
// 実験 3: リレーション先（products）も確実にロックしたい場合の書き方
// ============================================================
echo "\n=== 実験 3: with() クロージャ内での lockForUpdate() ===\n";
$conn->flushQueryLog();

// TODO 3: トランザクション内で、with(['product' => ...]) または join() を使って
//         order_items と products の両方にロックを張るクエリを書いて観測しよう
//         最後に rollBack() すること
$conn->beginTransaction();
OrderItem::with(['product' => fn($q) => $q->lockForUpdate()])
    ->lockForUpdate()->get();

$locksExp3 = $conn->select(
    "SELECT OBJECT_NAME, INDEX_NAME, LOCK_MODE, LOCK_STATUS, LOCK_DATA
     FROM performance_schema.data_locks
     WHERE OBJECT_SCHEMA = 'internals_lab'"
);

foreach ($conn->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}
echo "\n--- 観測されたロック ---\n";
foreach ($locksExp3 as $lock) {
    printf(
        "table=%-12s | index=%-10s | mode=%-16s | data=%s\n",
        $lock->OBJECT_NAME,
        $lock->INDEX_NAME ?? 'NULL',
        $lock->LOCK_MODE,
        $lock->LOCK_DATA ?? 'NULL'
    );
}

$conn->rollBack();
