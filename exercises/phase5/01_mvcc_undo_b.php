<?php

declare(strict_types=1);

/**
 * Phase 5-1 演習: Session B（Undo ログ生成 & History List Length 観測側）
 *
 * 使い方:
 *   Session A が待機中に別ターミナルから実行:
 *   docker compose exec php php exercises/phase5/01_mvcc_undo_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

function getHistoryListLength($conn): int
{
    $row = $conn->selectOne(
        "SELECT COUNT FROM INFORMATION_SCHEMA.INNODB_METRICS WHERE NAME = 'trx_rseg_history_len'"
    );
    return (int) ($row->COUNT ?? 0);
}

echo "=== Phase 5-1 Session B (Undo Log & History List Length) ===\n";
echo '更新前の History List Length: ' . getHistoryListLength($conn) . "\n";

// TODO 1: products.id = 10 の stock を 100 回ループで +1 更新（毎回コミット）しよう
//         （※ 各更新ごとに古いバージョンの Undo ログが生成される）
for ($i = 0; $i < 100; $i++) {
    $conn->table('products')->where('id', 10)->increment('stock');
}

echo '100回更新後の History List Length: ' . getHistoryListLength($conn) . "\n";
echo ">>> Session A がまだトランザクションを開いている間は、この値がパージされずに残ります！\n";
echo ">>> Session A で Enter を押して終了させたあと、数秒待ってから以下で再確認してみよう。\n\n";

echo "Enter キーを押すと 2 秒待ってから History List Length を再計測します: ";
fgets(STDIN);
sleep(2);
echo '再計測した History List Length: ' . getHistoryListLength($conn) . "\n";
