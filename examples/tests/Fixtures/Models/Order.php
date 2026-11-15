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

    /**
     * row-level-security/global-tenant-scope — the structural tenant filter.
     * NULL context means "no filter" (the inert-context danger the chapter
     * documents); production code sets it at every boundary and treats NULL
     * as fail-closed.
     */
    public static ?int $currentTenantId = null;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function ($query) {
            if (static::$currentTenantId !== null) {
                $query->where('tenant_id', static::$currentTenantId);
            }
        });
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'sku_id');
    }
}
