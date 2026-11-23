<?php

declare(strict_types=1);

/**
 * Phase 3-5 演習: Eloquent のクエリ速度・メモリ最適化
 *
 * 使い方:
 *   docker compose exec php php exercises/phase3/05_eloquent_perf.php
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\OrderItem;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$conn->table('perf_logs')->truncate();

$totalRows = 2000;

// ============================================================
// 実験 1: 1件ずつ INSERT ループ vs バルク INSERT の速度差
// ============================================================
echo "=== 実験 1: {$totalRows} 件の INSERT 速度比較 ===\n";

$startSingle = microtime(true);
// TODO 1: 1件ずつ $conn->table('perf_logs')->insert([...]) を $totalRows 回ループで実行しよう
for ($i = 0; $i < $totalRows; $i++) {
    $conn->table('perf_logs')->insert([
        'category'   => $i % 10,
        'payload'    => 'log_' . $i,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
$singleMs = round((microtime(true) - $startSingle) * 1000, 2);

$conn->table('perf_logs')->truncate();

$startBulk = microtime(true);
// TODO 2: $totalRows 件の配列を作り、array_chunk(..., 500) を使って
//         500件ずつバルク $conn->table('perf_logs')->insert($chunk) を実行しよう
$data = array_map(fn($i) => [
    'category'   => $i % 10,
    'payload'    => 'log_' . $i,
    'created_at' => date('Y-m-d H:i:s'),
], range(0, $totalRows - 1));
$chunks = array_chunk($data, 500);
foreach ($chunks as $chunk) {
    $conn->table('perf_logs')->insert($chunk);
}
$bulkMs = round((microtime(true) - $startBulk) * 1000, 2);

echo "1件ずつ INSERT: {$singleMs} ms\n";
echo "バルク INSERT:  {$bulkMs} ms\n\n";

// ============================================================
// 実験 2: chunk() vs chunkById() vs cursor() の発行 SQL と挙動
// ============================================================
echo "=== 実験 2: chunk() vs chunkById() の発行 SQL ===\n";

$conn->enableQueryLog();
$conn->flushQueryLog();

// TODO 3: perf_logs に対して ->orderBy('id')->chunk(500, ...) と
//         ->chunkById(500, ...) をそれぞれ実行し、発行される SQL の違い（OFFSET vs id > ?）を観測しよう
$startChunk = microtime(true);
$conn->table('perf_logs')->orderBy('id')->chunk(500, function () {});
$chunkMs = round((microtime(true) - $startChunk) * 1000, 2);

$startChunkById = microtime(true);
$conn->table('perf_logs')->chunkById(500, function () {});
$chunkByIdMs = round((microtime(true) - $startChunkById) * 1000, 2);

echo "chunk: {$chunkMs} ms\n";
echo "chunkById:  {$chunkByIdMs} ms\n\n";

foreach ($conn->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}
echo "\n";

// ============================================================
// 実験 3: whereHas() が裏で発行する相関サブクエリ (EXISTS) を確認する
// ============================================================
echo "=== 実験 3: whereHas() の発行 SQL ===\n";
$conn->flushQueryLog();

// TODO 4: OrderItem::whereHas('product', fn ($q) => $q->where('category_id', 20))->get() を実行し、
//         発行される SQL（EXISTS 相関サブクエリ）を確認しよう
OrderItem::whereHas('product', fn ($q) => $q->where('category_id', 20))->get();

foreach ($conn->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}
