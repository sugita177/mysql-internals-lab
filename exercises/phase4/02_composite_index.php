<?php

declare(strict_types=1);

/**
 * Phase 4-2 演習: カバリングインデックスと複合インデックスのカラム順序設計
 *
 * 使い方:
 *   1. database/migrations/007_create_orders_perf_table.php の // TODO: を実装
 *   2. docker compose exec php php lab migrate
 *   3. docker compose exec php php exercises/phase4/02_composite_index.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// 20,000 件のテストデータを投入
if ($conn->table('orders_perf')->count() < 20000) {
    echo "orders_perf に 20,000 件のテストデータを投入中...\n";
    $conn->table('orders_perf')->truncate();
    $statuses = ['pending', 'paid', 'shipped', 'cancelled'];
    $baseTime = strtotime('2026-10-01 00:00:00');
    $rows = [];

    for ($i = 1; $i <= 20000; $i++) {
        $rows[] = [
            'user_id'      => ($i % 500) + 1,
            'status'       => $statuses[$i % 4],
            'total_amount' => ($i % 50 + 1) * 100,
            'ordered_at'   => date('Y-m-d H:i:s', $baseTime + ($i * 180)),
        ];
        if (count($rows) === 1000) {
            $conn->table('orders_perf')->insert($rows);
            $rows = [];
        }
    }
    $conn->statement('ANALYZE TABLE orders_perf');
}

// TODO 1: 以下の 3 つのクエリに対して EXPLAIN を実行し、key / rows / Extra の違いを比較しよう
//   A) SELECT * で全カラム取得（idx_good_order が効くが、ブックマークルックアップが起きる）
//   B) SELECT id, user_id, status, ordered_at のみ取得（カバリングインデックス: Using index！）
//   C) WHERE から先頭カラム (user_id) を抜いた検索（左端一致の原則が崩れてどうなるか？）
$queries = [
    'A_select_star' => "SELECT * FROM orders_perf WHERE user_id = 100 AND status = 'paid' AND ordered_at >= '2026-11-01 00:00:00' ORDER BY ordered_at DESC LIMIT 20",
    'B_covering'    => "SELECT id, user_id, status, ordered_at FROM orders_perf WHERE user_id = 100 AND status = 'paid' AND ordered_at >= '2026-11-01 00:00:00' ORDER BY ordered_at DESC LIMIT 20",
    'C_no_leftmost' => "SELECT id, user_id, status, ordered_at FROM orders_perf WHERE status = 'paid' AND ordered_at >= '2026-11-01 00:00:00' ORDER BY ordered_at DESC LIMIT 20",
];

echo "=== 複合インデックス EXPLAIN 比較 ===\n";
foreach ($queries as $label => $sql) {
    // TODO 1: ここで EXPLAIN を実行し、type, key, rows, Extra を表示しよう
    if ($sql === '') {
        echo "[{$label}] (未実装)\n";
        continue;
    }
    $plan = $conn->select("EXPLAIN {$sql}");
    foreach ($plan as $row) {
        printf(
            "%-8s | type=%-6s | key=%-10s | rows=%-6s | Extra=%s\n",
            $label,
            $row->type ?? 'NULL',
            $row->key ?? 'NULL',
            $row->rows ?? '-',
            $row->Extra ?? ''
        );
    }
}
