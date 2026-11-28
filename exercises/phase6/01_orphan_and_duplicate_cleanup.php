<?php

declare(strict_types=1);

/**
 * Phase 6-1 演習: 孤児レコード・重複レコードの検出とウィンドウ関数による安全なクレンジング
 *
 * 使い方:
 *   1. docker compose exec php php lab migrate
 *   2. docker compose exec php php lab seed
 *   3. docker compose exec php php exercises/phase6/01_orphan_and_duplicate_cleanup.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// ============================================================
// 実験 1: 孤児レコード（Orphan Records）の検出
// ============================================================
echo "=== 実験 1: 親が存在しない legacy_orders の検出 ===\n";

// TODO 1: LEFT JOIN (WHERE c.id IS NULL) または NOT EXISTS を使って、
//         legacy_customers に存在しない customer_id を持つ孤児注文を抽出しよう
$orphans = $conn->select(
    "SELECT o.id, o.customer_id, o.amount FROM legacy_orders o LEFT JOIN legacy_customers c ON o.customer_id = c.id WHERE c.id IS NULL"
);

foreach ($orphans as $o) {
    echo "孤児注文: order_id={$o->id} | customer_id={$o->customer_id} | amount={$o->amount}\n";
}
echo "\n";

// ============================================================
// 実験 2: ウィンドウ関数 (ROW_NUMBER) による重複レコードの安全な特定と削除
// ============================================================
echo "=== 実験 2: 重複顧客の検出とクレンジング ===\n";

// TODO 2: WITH (CTE) と ROW_NUMBER() OVER (PARTITION BY email ORDER BY id ASC) AS rn を使って、
//         重複行 (rn > 1) の id と email をまず SELECT (Dry-run) して表示しよう
$duplicates = $conn->select(
    "WITH ranked AS (
        SELECT id, email, ROW_NUMBER() OVER (PARTITION BY email ORDER BY id ASC) AS rn FROM legacy_customers
    ) SELECT id, email, rn FROM ranked WHERE rn > 1"
);

foreach ($duplicates as $d) {
    echo "削除対象(重複): id={$d->id} | email={$d->email} | rn={$d->rn}\n";
}

// TODO 3: 確認できたら、トランザクション内で重複行 (rn > 1) を DELETE しよう
//         （※ MySQL では CTE の結果やサブクエリを JOIN して DELETE する書き方が安全）
//         削除後、残った legacy_customers を表示して 3 件 (Alice, Bob, Carol) だけになることを確認！
$conn->delete(
    "WITH ranked AS (
        SELECT id, email, ROW_NUMBER() OVER (PARTITION BY email ORDER BY id ASC) AS rn FROM legacy_customers
    ) DELETE c  FROM legacy_customers c INNER JOIN ranked r ON c.id = r.id WHERE rn > 1"
);
echo "=== 重複顧客のクレンジング後 ===\n";
$legacy_customers = $conn->table('legacy_customers')->select(['id', 'email'])->get();
foreach ($legacy_customers as $c) {
    echo "id={$c->id} | email={$c->email}\n";
}