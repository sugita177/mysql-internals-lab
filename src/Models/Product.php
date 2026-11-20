<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'sku',
        'category_id',
        'name',
        'stock',
        'price',
    ];
}
