<?php

declare(strict_types=1);

/**
 * Phase 3-3 演習: Worker A（SKIP LOCKED でタスクを 1 件掴んで処理中を再現）
 *
 * 使い方:
 *   docker compose exec php php exercises/phase3/03_skip_locked_worker_a.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$conn->enableQueryLog();

echo "=== Phase 3-3 Worker A ===\n";

try {
    // TODO 1: トランザクションを開始する
    $conn->beginTransaction();

    // TODO 2: job_tasks から status = 'pending' の先頭 1 件 (orderBy('id')) を
    //         lockForUpdate()->skipLocked()->first() で取得しよう
    // ヒント: Capsule::table('job_tasks')->where(...)->...
    $task = Capsule::table('job_tasks')
        ->where('status', 'pending')
        ->orderBy('id')
        ->lock('for update skip locked')
        ->first();

    if ($task === null) {
        echo "[Worker A] 処理対象の pending タスクがありません。\n";
        $conn->rollBack();
        exit(0);
    }

    foreach ($conn->getQueryLog() as $q) {
        echo '[SQL] ' . $q['query'] . "\n";
    }

    echo "[Worker A] タスクを取得しました: id={$task->id} ({$task->payload})\n";

    // ステータスを processing に更新（まだコミットせずロックを保持したまま待機）
    $conn->table('job_tasks')->where('id', $task->id)->update([
        'status' => 'processing',
        'worker' => 'worker-a',
    ]);

    echo ">>> 別ターミナルで Worker B を実行したあと、Enter キーを押して処理完了（COMMIT）にしてください: ";
    fgets(STDIN);

    // TODO 3: status = 'done' に更新してコミットする
    $conn->table('job_tasks')->where('id', $task->id)->update([
        'status' => 'done',
    ]);
    $conn->commit();
    echo "[Worker A] id={$task->id} を done にしてコミットしました。\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Worker A] Error: {$e->getMessage()}\n");
    exit(1);
}
