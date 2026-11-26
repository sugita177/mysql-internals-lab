<?php

declare(strict_types=1);

/**
 * Phase 5-2 演習: Redo ログとバイナリログ（binlog）の設定・イベント観測
 *
 * 使い方:
 *   docker compose exec php php exercises/phase5/02_redo_binlog.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// ============================================================
// 実験 1: 耐久性・性能に関わる Redo / binlog パラメータを確認する
// ============================================================
echo "=== 実験 1: Redo Log / Binary Log 設定値 ===\n";

// TODO 1: 以下のシステム変数を SELECT で取得して表示しよう
//   - @@innodb_flush_log_at_trx_commit (デフォルト 1: 毎回ディスクへ fsync)
//   - @@sync_binlog                    (デフォルト 1: 毎回 binlog を fsync)
//   - @@binlog_format                  (ROW)
//   - @@innodb_redo_log_capacity       (MySQL 8.0.30+ の Redo ログ容量)
$vars = $conn->selectOne("
    SELECT
        @@innodb_flush_log_at_trx_commit AS flush_log,
        @@sync_binlog AS sync_binlog,
        @@binlog_format AS binlog_format,
        @@innodb_redo_log_capacity AS redo_capacity
");
// redo_capacity はバイト数なので / 1024 / 1024 で MB 表示すると見やすいです
printf(
    "flush_log=%d | sync_binlog=%d | binlog_format=%s | redo_capacity=%s\n",
    $vars->flush_log,
    $vars->sync_binlog,
    $vars->binlog_format,
    $vars->redo_capacity / 1024 / 1024 . 'MB'
);


// ============================================================
// 実験 2: トランザクション実行前後で binlog のポジション変化を観測する
// ============================================================
echo "\n=== 実験 2: Binary Log ポジションの変化 ===\n";

// TODO 2: SHOW BINARY LOG STATUS (または SHOW MASTER STATUS / SHOW BINARY LOGS) を実行して
//         更新前後の binlog ファイル名と Position を取得しよう
//         ※ MySQL 8.4 では SHOW BINARY LOGS または SHOW BINARY LOG STATUS が使える
//   1) 更新前の binlog 位置を取得
//   2) $conn->transaction(...) で products.id = 10 の stock を +1 更新してコミット
//   3) 更新後の binlog 位置を取得し、Position が何バイト進んだか表示しよう
$before = $conn->selectOne('SHOW BINARY LOG STATUS');

$conn->transaction(function () use ($conn): void {
    $conn->table('products')->where('id', 10)->increment('stock');
});

$after = $conn->selectOne('SHOW BINARY LOG STATUS');
// $after->Position - $before->Position で増えたバイト数がわかります！
$increasedByte = $after->Position - $before->Position;
echo "binlogの増加バイト数: {$increasedByte} Byte\n";
