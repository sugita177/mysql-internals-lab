<?php

declare(strict_types=1);

/**
 * Phase 5-4 演習: 無停止スキーマ変更（Expand and Contract パターン）
 *
 * シナリオ:
 *   users_legacy テーブルの `phone` カラムを `mobile_number` に無停止で移行する。
 *   ローリングデプロイ中に「旧アプリ」と「新アプリ」が混在しても一切エラーが出ない手順を体験しよう。
 *
 * 使い方:
 *   docker compose exec php php exercises/phase5/04_expand_contract.php
 */

require __DIR__ . '/../../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$conn = Capsule::connection();

// もし前回の実験で mobile_number カラムが残っていれば初期化
if (Capsule::schema()->hasColumn('users_legacy', 'mobile_number')) {
    $conn->statement('ALTER TABLE users_legacy DROP COLUMN mobile_number, ALGORITHM=INSTANT');
}

echo "=== Phase 5-4: Expand and Contract シミュレーション ===\n";

// ------------------------------------------------------------
// Step 1 (Expand): 新カラム mobile_number を NULL 許容・INSTANT で追加
// ------------------------------------------------------------
// TODO 1: users_legacy に mobile_number VARCHAR(20) NULL を ALGORITHM=INSTANT で追加しよう
$conn->statement('ALTER TABLE users_legacy ADD COLUMN mobile_number VARCHAR(20) NULL, ALGORITHM=INSTANT');
echo "[Step 1: Expand] mobile_number カラムを INSTANT で追加しました。\n";

// ------------------------------------------------------------
// Step 2 (Dual-Write & Backfill):
//   アプリ側で新旧両方に書き込む (Dual-Write) 状態にしつつ、
//   既存データの NULL を主キー範囲でチャンク分割して埋める (Backfill)
// ------------------------------------------------------------
$maxId = (int) $conn->table('users_legacy')->max('id');
$chunkSize = 2000;
$updatedTotal = 0;

// TODO 2: id = 1 から $maxId まで $chunkSize 刻みでループし、
//         mobile_number IS NULL の行に対して phone の値を mobile_number にコピーしよう
//         （1回の UPDATE で全10,000行をロックしないように id BETWEEN ? AND ? で分割！）
for ($startId = 1; $startId <= $maxId; $startId += $chunkSize) {
    $endId = min($startId + $chunkSize - 1, $maxId);
    $affected = $conn->table('users_legacy')
        ->whereBetween('id', [$startId, $endId])
        ->whereNull('mobile_number')
        ->update([
            'mobile_number' => Capsule::raw('phone'),
        ]);
    $updatedTotal += $affected;
}
echo "[Step 2: Backfill] 既存データ {$updatedTotal} 件をチャンク分割で移行しました。\n";

// 移行漏れ（NULL）が 0 件になったことを検証
$remainingNull = $conn->table('users_legacy')->whereNull('mobile_number')->count();
echo "[検証] mobile_number が NULL の残り行数: {$remainingNull} 件\n";

// ------------------------------------------------------------
// Step 3 (Switch Read): アプリを「新カラムのみ読み書き」へ切り替え
// Step 4 (Contract): 旧カラム phone を削除（今回は実験後の原状回復のため mobile_number を削除）
// ------------------------------------------------------------
// TODO 3: 今回は元のテーブル定義を保つため、Step 4 の掃除役として
//         mobile_number を ALGORITHM=INSTANT で削除して原状回復しよう
$conn->statement('ALTER TABLE users_legacy DROP COLUMN mobile_number, ALGORITHM=INSTANT');
echo "[Step 4: Contract] 不要になった一時カラムを INSTANT で削除して完了！\n";
