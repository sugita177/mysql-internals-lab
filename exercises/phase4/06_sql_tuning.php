<?php

declare(strict_types=1);

/**
 * Phase 4-6 演習: サブクエリ・集計（Using temporary）・SELECT * のチューニング
 *
 * 使い方:
 *   docker compose exec php php exercises/phase4/06_sql_tuning.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// ============================================================
// 実験 1: GROUP BY で Using temporary が出るケース vs 出ないケース
// ============================================================
echo "=== 実験 1: GROUP BY と Using temporary ===\n";

// TODO 1: orders_perf に対して以下の 2 つの GROUP BY クエリの EXPLAIN を比較しよう
//   A) status ごとの件数 (status は idx_good_order の先頭ではない)
//   B) user_id ごとの件数 (user_id は idx_good_order の先頭！)
$groupQueries = [
    'A_group_by_status'  => 'SELECT status, COUNT(*) FROM orders_perf GROUP BY status',
    'B_group_by_user_id' => 'SELECT user_id, COUNT(*) FROM orders_perf GROUP BY user_id',
];

foreach ($groupQueries as $label => $sql) {
    if ($sql === '') {
        echo "[{$label}] (未実装)\n";
        continue;
    }
    foreach ($conn->select("EXPLAIN {$sql}") as $row) {
        printf("%-20s | type=%-6s | key=%-15s | Extra=%s\n", $label, $row->type, $row->key ?? 'NULL', $row->Extra ?? '');
    }
}

// ============================================================
// 実験 2: SELECT * vs カバリング（必要カラムのみ）の EXPLAIN ANALYZE 比較
// ============================================================
echo "\n=== 実験 2: SELECT * vs カラム限定（8,000行読み取り比較） ===\n";

$sqlStar     = "SELECT * FROM orders_perf WHERE user_id BETWEEN 1 AND 200";
$sqlCovering = "SELECT id, user_id, status, ordered_at FROM orders_perf WHERE user_id BETWEEN 1 AND 200";

// TODO 2: 上記 2 つの SQL に対して EXPLAIN ANALYZE を実行し、
//         実測時間 (actual time) と実行計画の違い（Filter / Index lookup vs Covering index）を比較しよう
foreach (['SELECT *' => $sqlStar, 'Covering' => $sqlCovering] as $label => $sql) {
    $res = $conn->selectOne("EXPLAIN ANALYZE {$sql}");
    echo "--- {$label} ---\n" . $res->EXPLAIN . "\n";
}
