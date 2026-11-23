<?php

declare(strict_types=1);

/**
 * Phase 3-3 演習: Worker B（Worker A がロック中の行をスキップして次のタスクを掴む）
 *
 * 使い方:
 *   Worker A が待機中に別ターミナルから実行:
 *   docker compose exec php php exercises/phase3/03_skip_locked_worker_b.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$conn->enableQueryLog();

echo "=== Phase 3-3 Worker B ===\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    $start = microtime(true);

    // TODO 2: Worker A とまったく同じクエリ（lockForUpdate()->skipLocked()->first()）を実行しよう
    //         （比較実験として、skipLocked() を外した場合に 15秒待たされるかも後で試してみよう）
    $task = Capsule::table('job_tasks')
        ->where('status', 'pending')
        ->orderBy('id')
        ->lock('for update skip locked')
        ->first();

    $elapsedMs = round((microtime(true) - $start) * 1000, 2);

    if ($task === null) {
        echo "[Worker B] 取得できるタスクがありません（{$elapsedMs} ms）\n";
        $conn->rollBack();
        exit(0);
    }

    foreach ($conn->getQueryLog() as $q) {
        echo '[SQL] ' . $q['query'] . "\n";
    }

    echo "[Worker B] 待ち時間 {$elapsedMs} ms でタスクを取得: id={$task->id} ({$task->payload})\n";

    // TODO 3: status = 'done', worker = 'worker-b' に更新してコミットする
    $conn->table('job_tasks')->where('id', $task->id)->update([
        'status' => 'done',
        'worker' => 'worker-b',
    ]);
    $conn->commit();
    echo "[Worker B] id={$task->id} を done にしてコミットしました。\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Worker B] Error: {$e->getMessage()}\n");
    exit(1);
}
