<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_type
 * @property array $payload
 * @property int $aggregate_id
 * @property \Carbon\CarbonInterface|null $dispatched_at
 */
class OutboxMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_type', 'payload', 'aggregate_id'];

    protected $casts = ['payload' => 'array'];
}
