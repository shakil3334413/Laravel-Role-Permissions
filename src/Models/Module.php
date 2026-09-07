<?php

namespace Shakil\Permissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $table = 'rbac_modules';

    protected $fillable = ['name', 'slug'];

    public function submodules(): HasMany
    {
        return $this->hasMany(Submodule::class);
    }

    // All permissions in this module, including those under submodules.
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    public function directPermissions(): HasMany
    {
        return $this->permissions()->whereNull('submodule_id');
    }
}
