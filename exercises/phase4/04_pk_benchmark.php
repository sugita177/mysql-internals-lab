<?php

declare(strict_types=1);

/**
 * Phase 4-4 演習: 主キー選定（AUTO_INCREMENT vs UUIDv4 vs UUIDv7）とページ分割の実験
 *
 * 使い方:
 *   1. database/migrations/009_create_pk_benchmark_tables.php の // TODO: を実装
 *   2. docker compose exec php php lab migrate
 *   3. docker compose exec php php exercises/phase4/04_pk_benchmark.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

function generateUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function generateUuidV7Binary(int $seq): string
{
    // 先頭 48bit に単調増加するミリ秒タイムスタンプ + シーケンスを埋め込む（時系列ソート可能）
    $ms = (int) (microtime(true) * 1000) + $seq;
    $timeHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
    $randBytes = random_bytes(10);
    $randBytes[0] = chr((ord($randBytes[0]) & 0x0f) | 0x70); // version 7
    $randBytes[2] = chr((ord($randBytes[2]) & 0x3f) | 0x80); // variant
    return hex2bin($timeHex) . $randBytes; // 16 bytes binary
}

$totalRows = 20000;
$chunkSize = 1000;

foreach (['pk_auto_inc', 'pk_uuid_v4', 'pk_uuid_v7'] as $table) {
    $conn->table($table)->truncate();
}

echo "=== {$totalRows} 件の挿入ベンチマーク ===\n";

// TODO 1: pk_auto_inc, pk_uuid_v4, pk_uuid_v7 のそれぞれに $totalRows 件を
//         $chunkSize 件ずつバルクインサートし、かかった時間 (ms) を計測しよう
foreach (['pk_auto_inc', 'pk_uuid_v4', 'pk_uuid_v7'] as $table) {
    $start = microtime(true);
    $batch = [];

    for ($i = 1; $i <= $totalRows; $i++) {
        $row = [
            'category' => $i % 100,
            'payload'  => "payload_data_{$i}",
        ];
        if ($table === 'pk_uuid_v4') {
            $row['id'] = generateUuidV4();
        }
        if ($table === 'pk_uuid_v7') {
            $row['id'] = generateUuidV7Binary($i);
        }

        $batch[] = $row;
        if (count($batch) === $chunkSize) {
            // バルクインサートして $batch = [] にリセット
            $conn->table($table)->insert($batch);
            $batch = [];
        }
    }
    if ($batch !== []) {
        $conn->table($table)->insert($batch);
    }

    $ms = round((microtime(true) - $start) * 1000, 2);
    echo "{$table}: {$ms} ms\n";
}


// 統計情報を最新化
$conn->statement('ANALYZE TABLE pk_auto_inc, pk_uuid_v4, pk_uuid_v7');

echo "\n=== テーブル・インデックスサイズ比較 (INFORMATION_SCHEMA.TABLES) ===\n";
// TODO 2: INFORMATION_SCHEMA.TABLES から TABLE_NAME, TABLE_ROWS, DATA_LENGTH (主キー本体),
//         INDEX_LENGTH (セカンダリインデックス), DATA_FREE (断片化領域) を取得して比較表示しよう
// ヒント: TABLE_SCHEMA = 'internals_lab' AND TABLE_NAME IN ('pk_auto_inc', 'pk_uuid_v4', 'pk_uuid_v7')
$stats = $conn->select(
    "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = 'internals_lab'
      AND TABLE_NAME IN ('pk_auto_inc', 'pk_uuid_v4', 'pk_uuid_v7')
    ORDER BY DATA_LENGTH ASC"
);

foreach ($stats as $s) {
    printf(
        "%-12s | rows=%-6d | DATA=%7.1f KB | INDEX=%7.1f KB | FREE=%7.1f KB\n",
        $s->TABLE_NAME,
        $s->TABLE_ROWS,
        $s->DATA_LENGTH / 1024,
        $s->INDEX_LENGTH / 1024,
        $s->DATA_FREE / 1024
    );
}
