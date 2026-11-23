<?php

declare(strict_types=1);

/**
 * Phase 4-1 演習: EXPLAIN / EXPLAIN ANALYZE の読み方（type, key, rows, Extra）
 *
 * 使い方:
 *   docker compose exec php php exercises/phase4/01_explain_basics.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// 10,000 件なければテストデータを補充する
$count = $conn->table('perf_logs')->count();
if ($count < 10000) {
    echo "テストデータを 10,000 件に補充中...\n";
    $conn->table('perf_logs')->truncate();
    $now = date('Y-m-d H:i:s');
    $rows = [];
    for ($i = 1; $i <= 10000; $i++) {
        $rows[] = [
            'category'   => $i % 100, // 0~99 の均等分布（各カテゴリ約 100 件）
            'payload'    => "log_payload_{$i}",
            'created_at' => $now,
        ];
        if (count($rows) === 1000) {
            $conn->table('perf_logs')->insert($rows);
            $rows = [];
        }
    }
    $conn->statement('ANALYZE TABLE perf_logs');
}

// ============================================================
// 実験 1: EXPLAIN の type（const / ref / range / index / ALL）を比較する
// ============================================================

// TODO 1: 以下の 5 つのアクセスタイプが出る SQL をそれぞれ書いて EXPLAIN を実行しよう
//   1) const : 主キー id の等価検索
//   2) ref   : 非一意インデックス category の等価検索
//   3) range : 主キー id の範囲検索 (BETWEEN 100 AND 500 など)
//   4) index : インデックスカラム (category) だけを全件取得 (SELECT category FROM perf_logs)
//   5) ALL   : インデックスがないカラム (payload) での検索
$queries = [
    '1_const' => 'SELECT * FROM perf_logs WHERE id = 100', // <-- 例: 'SELECT * FROM perf_logs WHERE id = 100'
    '2_ref'   => 'SELECT * FROM perf_logs WHERE category = 10',
    '3_range' => 'SELECT * FROM perf_logs WHERE id BETWEEN 100 AND 500',
    '4_index' => 'SELECT category FROM perf_logs',
    '5_ALL'   => 'SELECT * FROM perf_logs WHERE payload LIKE "%log_payload_1%"',
];

echo "=== 実験 1: EXPLAIN 比較 ===\n";
foreach ($queries as $label => $sql) {
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

echo "\n=== 実験 2: EXPLAIN ANALYZE（予測値と実測値の比較） ===\n";
// TODO 2: $queries['2_ref'] と $queries['5_ALL'] に対して
//         $conn->select("EXPLAIN ANALYZE {$sql}") を実行し、結果を出力しよう
//         （EXPLAIN ANALYZE は 'EXPLAIN' カラムにツリー文字列を返す）
foreach (['2_ref', '5_ALL'] as $label) {
    $sql = $queries[$label];
    $row = $conn->selectOne("EXPLAIN ANALYZE {$sql}");
    echo "--- {$label} ---\n";
    echo $row->EXPLAIN . "\n";
}
