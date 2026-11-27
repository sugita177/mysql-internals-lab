<?php

declare(strict_types=1);

/**
 * Phase 5-3 演習: Session B（ALTER TABLE による MDL 待ち & オンライン DDL 検証）
 *
 * 使い方:
 *   Session A が待機中に別ターミナルから実行:
 *   docker compose exec php php exercises/phase5/03_mdl_accident_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;

echo "=== Phase 5-3 Session B (ALTER TABLE & Online DDL) ===\n";
echo "CONNECTION_ID: {$connId}\n\n";

// もし前回の実験で note カラムが残っていれば先に削除しておく
if (Capsule::schema()->hasColumn('products', 'note')) {
    $conn->statement('ALTER TABLE products DROP COLUMN note');
}

echo "[Session B] ALTER TABLE (ALGORITHM=INSTANT) を実行します...\n";
echo "  (Session A が開いている間は Waiting for table metadata lock で止まります!)\n";

$start = microtime(true);

// TODO 1: products テーブルに note VARCHAR(100) NULL カラムを
//         ALGORITHM=INSTANT で追加する ALTER TABLE 文を実行しよう
// ヒント: $conn->statement('ALTER TABLE products ADD COLUMN note VARCHAR(100) NULL, ALGORITHM=INSTANT');
$conn->statement('ALTER TABLE products ADD COLUMN note VARCHAR(100) NULL, ALGORITHM=INSTANT');

$elapsedMs = round((microtime(true) - $start) * 1000, 2);
echo "[Session B] ALTER TABLE 完了！（所要時間: {$elapsedMs} ms）\n";

// 後片付け: 追加したカラムを削除
if (Capsule::schema()->hasColumn('products', 'note')) {
    $conn->statement('ALTER TABLE products DROP COLUMN note, ALGORITHM=INSTANT');
}
