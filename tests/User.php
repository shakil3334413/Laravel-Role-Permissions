<?php

namespace Shakil\Permissions\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Shakil\Permissions\Concerns\HasRoles;
use Shakil\Permissions\Contracts\HasPermissions;

class User extends Authenticatable implements HasPermissions
{
    use HasRoles;

    protected $guarded = [];
}
