<?php

declare(strict_types=1);

/**
 * Phase 4-5 演習: InnoDB バッファプールと統計情報（ANALYZE TABLE）の観測
 *
 * 使い方:
 *   docker compose exec php php exercises/phase4/05_buffer_pool_stats.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// ============================================================
// 実験 1: InnoDB バッファプールの設定サイズとメモリヒット率を確認する
// ============================================================
echo "=== 実験 1: InnoDB バッファプール状態 ===\n";

// TODO 1: @@innodb_buffer_pool_size を取得して MB 単位で表示し、
//         さらに SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read%' で
//         - Innodb_buffer_pool_read_requests (メモリからの論理読み込み回数)
//         - Innodb_buffer_pool_reads (ディスクからの物理読み込み回数)
//         を取得して、キャッシュヒット率 (%) を計算・表示しよう
$poolSizeBytes = (int) $conn->selectOne('SELECT @@innodb_buffer_pool_size AS size')->size;
$poolSizeMb = round($poolSizeBytes / 1024 / 1024, 1);
echo "サイズ: " . $poolSizeMb . "MB\n";

$statusRows = $conn->select("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read%'");
$status = [];
foreach ($statusRows as $row) {
    $status[$row->Variable_name] = (int) $row->Value;
}

$requests = $status['Innodb_buffer_pool_read_requests'] ?? 0;
$reads    = $status['Innodb_buffer_pool_reads'] ?? 0;// ディスクからの読み込み回数
echo "requests: {$requests}, reads: {$reads}\n";
// ヒット率 (%) = (1 - ($reads / $requests)) * 100
$hitRatePercent = $requests > 0 ? round((1 - ($reads / $requests)) * 100, 3) : "リクエストが0なので、計算不可";
echo "ヒット率: {$hitRatePercent}%\n";



// ============================================================
// 実験 2: インデックス統計情報（Cardinality）と ANALYZE TABLE
// ============================================================
echo "\n=== 実験 2: SHOW INDEX と ANALYZE TABLE ===\n";

// TODO 2: orders_perf テーブルに対して以下を実行しよう
//   1) ANALYZE TABLE orders_perf を実行して統計情報を再計算させる
//   2) SHOW INDEX FROM orders_perf を実行し、Key_name / Column_name / Cardinality を表示する
//      （Cardinality が高いほど「値の重複が少ない＝絞り込みやすい」とオプティマイザが判断する）
$conn->statement('ANALYZE TABLE orders_perf');
$indexes = $conn->select('SHOW INDEX FROM orders_perf');
foreach ($indexes as $idx) {
    printf(
        "key=%-15s | seq=%d | col=%-12s | Cardinality=%s\n",
        $idx->Key_name,
        $idx->Seq_in_index,
        $idx->Column_name,
        $idx->Cardinality ?? 'NULL'
    );
}