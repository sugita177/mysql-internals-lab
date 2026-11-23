<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

return static function (): void {
    $now = date('Y-m-d H:i:s');

    Capsule::table('job_tasks')->truncate();

    $tasks = [];
    for ($i = 1; $i <= 5; $i++) {
        $tasks[] = [
            'payload'    => "Send Email Job #{$i}",
            'status'     => 'pending',
            'worker'     => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    Capsule::table('job_tasks')->insert($tasks);
};
