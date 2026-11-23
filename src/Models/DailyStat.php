<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyStat extends Model
{
    protected $table = 'daily_stats';

    protected $fillable = [
        'metric_key',
        'count',
    ];
}
