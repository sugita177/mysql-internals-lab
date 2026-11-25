<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => static function (): void {
        if (!Capsule::schema()->hasTable('pk_auto_inc')) {
            Capsule::schema()->create('pk_auto_inc', function (Blueprint $table): void {
                $table->id(); // BIGINT UNSIGNED AUTO_INCREMENT (8 bytes)
                $table->unsignedInteger('category')->index();
                $table->string('payload', 100);
            });
        }

        if (!Capsule::schema()->hasTable('pk_uuid_v4')) {
            Capsule::schema()->create('pk_uuid_v4', function (Blueprint $table): void {
                // ランダム挿入 + 36バイト文字列の主キー
                $table->char('id', 36)->primary();
                $table->unsignedInteger('category')->index();
                $table->string('payload', 100);
            });
        }

        if (!Capsule::schema()->hasTable('pk_uuid_v7')) {
            Capsule::schema()->create('pk_uuid_v7', function (Blueprint $table): void {
                // 時系列ソート可能 + 16バイトバイナリの主キー
                // TODO: BINARY(16) の主キーを定義しよう
                // ヒント: Blueprint には $table->binary('id', 16)->primary(); は環境により BLOB になる場合があるため、
                //         確実に BINARY(16) にするには作成後に ALTER または raw SQL、あるいは CHAR(36) との比較でも可。
                //         ここでは Capsule::statement() で BINARY(16) を定義するか、下のコメントを参考に定義してみよう。
                $table->unsignedInteger('category')->index();
                $table->string('payload', 100);
            });

            // MySQL の BINARY(16) 主キーを明示的に追加する例:
            Capsule::statement('ALTER TABLE pk_uuid_v7 ADD COLUMN id BINARY(16) NOT NULL FIRST, ADD PRIMARY KEY (id)');
        }
    },
];
