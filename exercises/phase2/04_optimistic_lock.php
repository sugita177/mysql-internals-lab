<?php

declare(strict_types=1);

/**
 * Phase 2-4 演習: 楽観的ロック（Optimistic Locking）によるデッドロック・Lost Update 回避
 *
 * シナリオ:
 *   ロック（FOR UPDATE）を一切使わずに、2つの独立したコネクション（Session A / B）が
 *   同じ商品（id = 10）を読み取り、それぞれ在庫を更新しようとする。
 *   version カラムを使った CAS（Compare-And-Swap）で後勝ち（Lost Update）を防ごう。
 *
 * 使い方:
 *   docker compose exec php php exercises/phase2/04_optimistic_lock.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// 2つ目の独立した DB コネクションを登録（別ユーザーのリクエストをシミュレート）
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
], 'session_b');

$connA = Capsule::connection('default');
$connB = Capsule::connection('session_b');

// 1. 両セッションがロックなしで同じ商品（id = 10）を読み取る（例: 画面表示時）
$itemA = $connA->table('products')->where('id', 10)->first();
$itemB = $connB->table('products')->where('id', 10)->first();

echo "[Session A] 読み取り: stock={$itemA->stock}, version={$itemA->version}\n";
echo "[Session B] 読み取り: stock={$itemB->stock}, version={$itemB->version}\n\n";

// 2. Session A が先に更新を確定する（version が一致する場合のみ更新し、version を +1）
// TODO 1: Session A で id = 10 かつ version = $itemA->version の行だけを更新しよう
//         更新内容: stock を $itemA->stock - 1 に、version を $itemA->version + 1 にする
//         戻り値（更新件数 $affectedA）を受け取ろう
$affectedA = $connA->table('products')
    ->where('id', 10)
    ->where('version', $itemA->version) // ← 読んだ時点の version と一致するときだけ更新！
    ->update([
        'stock'   => $itemA->stock - 1,   // または Capsule::raw('stock - 1')
        'version' => $itemA->version + 1, // または Capsule::raw('version + 1')
    ]);

echo "[Session A] 更新件数: {$affectedA} 件\n";

// 3. 続いて Session B が（古い version のまま）更新を試みる
// TODO 2: Session B で id = 10 かつ version = $itemB->version の行だけを更新しよう
//         更新内容: stock を $itemB->stock - 2 に、version を $itemB->version + 1 にする
$affectedB = $connB->table('products')
    ->where('id', 10)
    ->where('version', $itemB->version)
    ->update([
        'stock' => $itemB->stock - 2,
        'version' => $itemB->version + 1,
    ]);


echo "[Session B] 更新件数: {$affectedB} 件\n\n";

// TODO 3: $affectedB === 0 の場合に「他者によって更新されたため競合エラー」と判定して
//         再読み込みまたはエラー通知する分岐を書こう
if ($affectedB === 0) {
    throw new RuntimeException(
        '他のユーザーによって先に更新されたため、保存できませんでした。最新データを再読み込みしてください。'
    );
}
