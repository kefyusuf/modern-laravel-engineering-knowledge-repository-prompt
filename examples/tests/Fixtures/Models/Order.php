<?php

namespace MLE\Examples\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harness model for the Eloquent chapter.
 */
class Order extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'sku_id', 'quantity', 'status', 'placed_at', 'merchant_id', 'tenant_id'];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'sku_id');
    }
}
