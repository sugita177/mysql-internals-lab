<?php

declare(strict_types=1);

/**
 * Phase 1 演習: Session A（ロックを取得して保持する側）
 *
 * 使い方:
 *   docker compose exec php php exercises/phase1/01_lock_scope_session_a.php
 *
 * 課題:
 *   以下の // TODO: を自分で実装し、Session A がトランザクション内でロックを保持している間に、
 *   別ターミナルから Session B で performance_schema.data_locks を観測しよう。
 */

require __DIR__ . '/../../bootstrap.php';

use App\Models\Product;
use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();
$connId = $conn->selectOne('SELECT CONNECTION_ID() AS id')->id;
$isolation = $conn->selectOne('SELECT @@transaction_isolation AS iso')->iso;

echo "=== Session A ===\n";
echo "CONNECTION_ID:   {$connId}\n";
echo "ISOLATION LEVEL: {$isolation}\n\n";

try {
    // TODO 1: (ケース5で試す場合) 分離レベルを変更する SQL をここに追加してみよう
    // ヒント: SET SESSION TRANSACTION ISOLATION LEVEL ...
    //$conn->statement("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

    // TODO 2: トランザクションを開始する
    // ヒント: Capsule::connection()->beginTransaction();
    Capsule::connection()->beginTransaction();

    echo "[Session A] トランザクションを開始し、ロックを取得します...\n";

    // TODO 3: 排他ロック（FOR UPDATE）を取得するクエリを実装する
    // まずは ケース1: 主キーで存在する行（id = 20）を Eloquent または Query Builder でロックしてみよう
    //   例: Product::where(...)->lockForUpdate()->get();
    // 慣れてきたら ケース2 (id = 25), ケース3 (category_id = 20), ケース4 (name = 'Ergonomic Mouse') に書き換えて比較しよう
    //$rows = Product::where('id', 20)->lockForUpdate()->get();
    //$rows = Product::where('id', 25)->lockForUpdate()->get();
    //$rows = Product::where('category_id', 20)->lockForUpdate()->get();
    //$rows = Product::where('name', 'Ergonomic Mouse')->lockForUpdate()->get();

    $rows = Product::where('id', 20)->sharedLock()->get();

    echo '[Session A] 取得件数: ' . count($rows) . " 件\n";
    echo "[Session A] ロックを保持したまま待機します。別ターミナルで Session B を実行してください。\n";
    echo ">>> Enter キーを押すと ROLLBACK して終了します: ";
    fgets(STDIN);

    // TODO 4: トランザクションをロールバック（またはコミット）してロックを解放する
    Capsule::connection()->rollBack();
    echo "[Session A] トランザクションを終了しました。\n";
} catch (Throwable $e) {
    if ($conn->transactionLevel() > 0) {
        $conn->rollBack();
    }
    fwrite(STDERR, "[Session A] Error: {$e->getMessage()}\n");
    exit(1);
}
