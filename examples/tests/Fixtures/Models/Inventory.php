<?php

namespace MLE\Examples\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Harness model for the Eloquent chapter: inventories own orders
 * (orders.sku_id → inventories.id).
 */
class Inventory extends Model
{
    public $timestamps = false;

    protected $fillable = ['id', 'available'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'sku_id');
    }
}
