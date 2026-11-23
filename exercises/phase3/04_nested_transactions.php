<?php

declare(strict_types=1);

/**
 * Phase 3-4 演習: ネストしたトランザクションと SAVEPOINT の挙動
 *
 * 使い方:
 *   docker compose exec php php exercises/phase3/04_nested_transactions.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$conn->table('daily_stats')->truncate();

// ============================================================
// 実験 1: ネストした transaction() と SAVEPOINT / ROLLBACK TO SAVEPOINT
// ============================================================
echo "=== 実験 1: ネストしたトランザクションと部分ロールバック ===\n";

$conn->enableQueryLog();
$conn->flushQueryLog();

// TODO 1: $conn->transaction() で外側のトランザクションを開始し、
//         1) daily_stats に 'outer_before' (count = 1) を INSERT
//         2) 内側の $conn->transaction() を try-catch で囲んで実行:
//            - 'inner_item' (count = 99) を INSERT
//            - 意図的に RuntimeException を投げて内側のトランザクションだけ失敗させる
//         3) catch ブロックでエラーを握りつぶしてログだけ出し、
//            外側の処理として 'outer_after' (count = 2) を INSERT して正常終了する
$conn->beginTransaction();
$conn->table('daily_stats')->insert([
    'metric_key' => 'outer_before',
    'count' => 1,
]);

try {
    $conn->transaction(function () use ($conn): void {
        $conn->table('daily_stats')->insert([
            'metric_key' => 'inner_item',
            'count' => 99,
        ]);
        throw new RuntimeException('内側のトランザクションが失敗しました');
    });
} catch (Throwable $e) {
    echo "[Error] {$e->getMessage()}\n";
}

$conn->table('daily_stats')->insert([
    'metric_key' => 'outer_after',
    'count' => 2,
]);
$conn->commit();

foreach ($conn->getQueryLog() as $q) {
    echo '[SQL] ' . $q['query'] . "\n";
}

$rows = $conn->table('daily_stats')->orderBy('id')->get(['id', 'metric_key', 'count']);
echo "\n--- 実験 1 終了後の daily_stats ---\n";
foreach ($rows as $row) {
    echo "id={$row->id} | key={$row->metric_key} | count={$row->count}\n";
}

// ============================================================
// 実験 2: トランザクション内での「外部副作用」と attempts リトライの罠
// ============================================================
echo "\n=== 実験 2: attempts リトライ時の副作用 ===\n";

$externalApiCallCount = 0;

try {
    // TODO 2: $conn->transaction(function () use ($conn, &$externalApiCallCount) { ... }, 3) を使い、
    //         1) 'retry_test' を INSERT（または UPDATE）
    //         2) $externalApiCallCount++ （外部決済APIやメール送信を想定）
    //         3) 1回目・2回目は意図的に Deadlock 相当の例外（または $attempts でリトライされる挙動）を確認しよう
    //            ※ ヒント: Laravel の transaction($fn, 3) は「デッドロック系メッセージ」のときだけリトライする！
    //            実際に "Deadlock found when trying to get lock" を含む PDOException / Exception を投げて試してみよう
    $conn->transaction(function () use ($conn, &$externalApiCallCount): void {
        $conn->table('daily_stats')->insert([
            'metric_key' => 'retry_test',
            'count' => 1,
        ]);
        $externalApiCallCount++;
        throw new PDOException('Deadlock found when trying to get lock');
    }, 3);
} catch (Throwable $e) {
    echo "[Error] {$e->getMessage()}\n";
}

echo "外部 API 呼び出し回数: {$externalApiCallCount} 回\n";
