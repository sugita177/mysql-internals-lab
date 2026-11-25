<?php

declare(strict_types=1);

/**
 * Phase 4-3 演習: インデックスが無効化されるパターンとオプティマイザの判断
 *
 * 使い方:
 *   1. docker compose exec php php lab migrate
 *   2. TODO 1 を実装して docker compose exec php php exercises/phase4/03_index_invalidation.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

if ($conn->table('users_legacy')->count() < 10000) {
    echo "users_legacy に 10,000 件のテストデータを投入中...\n";
    $conn->table('users_legacy')->truncate();
    $baseTime = strtotime('2026-11-01 00:00:00');
    $rows = [];

    for ($i = 1; $i <= 10000; $i++) {
        $rows[] = [
            'phone'         => sprintf('090%08d', $i),
            'email'         => "user{$i}@example.com",
            'is_active'     => ($i % 20 === 0) ? 0 : 1, // 95% が 1、5% が 0
            'registered_at' => date('Y-m-d H:i:s', $baseTime + ($i * 120)),
        ];
        if (count($rows) === 1000) {
            $conn->table('users_legacy')->insert($rows);
            $rows = [];
        }
    }
    $conn->statement('ANALYZE TABLE users_legacy');
}

// TODO 1: 以下の「悪い書き方 (BAD)」と「インデックスが効く書き方 (GOOD)」をそれぞれ書いて、
//         EXPLAIN の type / key / rows の違いを検証しよう
$cases = [
    // 1. 暗黙の型変換: phone (VARCHAR) に対して数値 9000000100 vs 文字列 '09000000100'
    '1_bad_implicit_cast'   => 'SELECT * FROM users_legacy WHERE phone = 9000000100',
    '1_good_string_type'    => 'SELECT * FROM users_legacy WHERE phone = "09000000100"',

    // 2. 関数適用: DATE(registered_at) = '2026-11-05' vs 範囲検索 (>= AND <)
    '2_bad_func_on_col'     => 'SELECT * FROM users_legacy WHERE DATE(registered_at) = "2026-11-05"',
    '2_good_range'          => 'SELECT * FROM users_legacy WHERE registered_at >= "2026-11-05 00:00:00" AND registered_at < "2026-11-06 00:00:00"',

    // 3. LIKE 検索: 中間一致 '%user100%' vs 前方一致 'user100%'
    '3_bad_like_middle'     => 'SELECT * FROM users_legacy WHERE email LIKE "%user100%"',
    '3_good_like_prefix'    => 'SELECT * FROM users_legacy WHERE email LIKE "user100%"',

    // 4. 低カーディナリティの統計判断: is_active = 1 (95%) vs is_active = 0 (5%)
    '4_low_card_95pct'      => 'SELECT * FROM users_legacy WHERE is_active = 1',
    '4_low_card_5pct'       => 'SELECT * FROM users_legacy WHERE is_active = 0',
];

echo "=== インデックス無効化 EXPLAIN 検証 ===\n";
foreach ($cases as $label => $sql) {
    if ($sql === '') {
        echo "[{$label}] (未実装)\n";
        continue;
    }
    $plan = $conn->select("EXPLAIN {$sql}");
    foreach ($plan as $row) {
        printf(
            "%-22s | type=%-6s | key=%-28s | rows=%-6s | Extra=%s\n",
            $label,
            $row->type ?? 'NULL',
            $row->key ?? 'NULL',
            $row->rows ?? '-',
            $row->Extra ?? ''
        );
    }
}
