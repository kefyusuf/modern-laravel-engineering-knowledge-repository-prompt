<?php

namespace MLE\Examples\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * authorization chapter subject: a user that belongs to a tenant and may
 * carry the admin bypass flag.
 */
class User extends Authenticatable
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'is_admin'];

    protected $casts = ['is_admin' => 'boolean'];
}
