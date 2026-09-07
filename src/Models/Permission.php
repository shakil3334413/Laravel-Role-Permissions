<?php

namespace Shakil\Permissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Shakil\Permissions\Support\PermissionScope;

class Permission extends Model
{
    protected $table = 'rbac_permissions';

    protected $fillable = ['name', 'slug', 'scope', 'module_id', 'submodule_id'];

    protected $attributes = ['scope' => 'web'];

    protected static function booted(): void
    {
        static::saving(fn (self $permission) => PermissionScope::assert($permission->scope));
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function submodule(): BelongsTo
    {
        return $this->belongsTo(Submodule::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'rbac_permission_role')->withPivot('scope')->withTimestamps();
    }
}
