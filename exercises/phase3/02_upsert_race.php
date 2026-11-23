<?php

declare(strict_types=1);

/**
 * Phase 3-2 演習: updateOrCreate() / firstOrCreate() のレースコンディションと upsert() の罠
 *
 * 使い方:
 *   docker compose exec php php exercises/phase3/02_upsert_race.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\DailyStat;
use Illuminate\Database\Capsule\Manager as Capsule;

// 2つ目の独立コネクションを登録
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => getenv('DB_HOST') ?: 'mysql',
    'port'      => getenv('DB_PORT') ?: '3306',
    'database'  => getenv('DB_DATABASE') ?: 'internals_lab',
    'username'  => getenv('DB_USERNAME') ?: 'lab_user',
    'password'  => getenv('DB_PASSWORD') ?: 'lab_secret',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_0900_ai_ci',
    'prefix'    => '',
    'strict'    => true,
    'engine'    => 'InnoDB',
], 'conn_b');

$connA = Capsule::connection('default');
$connB = Capsule::connection('conn_b');

// テーブルを初期化
$connA->table('daily_stats')->truncate();

// ============================================================
// 実験 1: updateOrCreate() が裏で発行する SQL を確認する
// ============================================================
echo "=== 実験 1: updateOrCreate() の発行 SQL ===\n";
$connA->enableQueryLog();
$connA->flushQueryLog();

// TODO 1: DailyStat::updateOrCreate() を使って、
//         metric_key = 'pv_2026_11_23' が無ければ count = 1 で作成するコードを書こう
DailyStat::updateOrCreate(
    ['metric_key' => 'pv_2026_11_23'],
    ['count' => 1],
);


foreach ($connA->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}
echo "\n";

// ============================================================
// 実験 2: SELECT → INSERT 間のレースコンディションを再現する
// ============================================================
echo "=== 実験 2: firstOrCreate / updateOrCreate 内部の競合再現 ===\n";
$key = 'race_key';

// Conn A と Conn B の両方がほぼ同時に「存在チェック (SELECT)」をしたとする
$existsA = $connA->table('daily_stats')->where('metric_key', $key)->first();
$existsB = $connB->table('daily_stats')->where('metric_key', $key)->first();

// TODO 2: 両方とも $exists === null だったため、続けて Conn A と Conn B の両方から
//         同じ metric_key = 'race_key' を INSERT してみよう（try-catch でエラーを捕捉して表示）
if ($existsA === null) {
    try {
        $connA->table('daily_stats')->insert([
            'metric_key' => $key,
        ]);
        echo "[Conn A] INSERT 成功\n";
    } catch (Throwable $e) {
        echo "[Conn A] Error: {$e->getMessage()}\n";
    }
}
if ($existsB === null) {
    try {
        $connB->table('daily_stats')->insert([
            'metric_key' => $key,
        ]);
    echo "[Conn B] INSERT 成功\n";
    } catch (Throwable $e) {
        echo "[Conn B] Error: {$e->getMessage()}\n";
    }
}

echo "\n";

// ============================================================
// 実験 3: upsert() の発行 SQL と AUTO_INCREMENT 消費の罠
// ============================================================
echo "=== 実験 3: upsert() と AUTO_INCREMENT の消費 ===\n";
$connA->flushQueryLog();

// TODO 3: DailyStat::upsert() を使って、既存の 'pv_2026_11_23' の count を 10 に更新し、
//         さらに新規の 'pv_2026_11_24' (count = 5) を登録しよう
//         （ヒント: upsert([行1, 行2], ['metric_key'], ['count']) のような引数形式）
//         その後、もう 1 回 'pv_2026_11_23' だけを更新する upsert() を実行し、
//         最後に新規行 'pv_2026_11_25' を INSERT して、id がいくつに飛ぶか確認しよう！
DailyStat::upsert(
    // 第1引数: 2次元配列（複数行をまとめて渡せる）
    [
        ['metric_key' => 'pv_2026_11_23', 'count' => 10],
        ['metric_key' => 'pv_2026_11_24', 'count' => 5],
    ],
    // 第2引数: 重複判定に使うユニークキー
    ['metric_key'],
    // 第3引数: 重複（既存行）だった場合に上書き更新するカラム
    ['count']
);

DailyStat::upsert(
    [['metric_key' => 'pv_2026_11_23', 'count' => 11]],
    ['metric_key'],
    ['count']
);

$connA->table('daily_stats')->insert([
    'metric_key' => 'pv_2026_11_25',
    'count' => 1,
]);

foreach ($connA->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}

$rows = DailyStat::orderBy('id')->get(['id', 'metric_key', 'count']);
echo "\n--- テーブル内の全レコード ---\n";
foreach ($rows as $row) {
    echo "id={$row->id} | key={$row->metric_key} | count={$row->count}\n";
}
