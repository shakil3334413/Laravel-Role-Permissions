<?php

namespace Shakil\Permissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;
use Shakil\Permissions\Support\PermissionScope;

class Role extends Model
{
    protected $table = 'rbac_roles';

    protected $fillable = ['name', 'slug', 'scope', 'is_active'];

    protected $attributes = ['scope' => 'web', 'is_active' => true];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            PermissionScope::assert($role->scope);
            // Assigned roles must never silently move between web and app.
            if ($role->exists && $role->isDirty('scope')) {
                throw new LogicException('A saved role cannot change scope. Create a new role instead.');
            }
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'rbac_permission_role')->withPivot('scope')->withTimestamps();
    }

    public function givePermissionTo(string $slug): static
    {
        if (! $this->exists) {
            throw new LogicException('Save the role before granting permissions.');
        }

        PermissionScope::assert($this->scope);
        $permission = Permission::query()->where('scope', $this->scope)->where('slug', $slug)->firstOrFail();
        $this->permissions()->syncWithoutDetaching([$permission->getKey() => ['scope' => $this->scope]]);
        $this->unsetRelation('permissions');

        return $this;
    }

    public function revokePermissionTo(string $slug): static
    {
        $permission = Permission::query()->where('scope', $this->scope)->where('slug', $slug)->firstOrFail();
        $this->permissions()->detach($permission->getKey());
        $this->unsetRelation('permissions');

        return $this;
    }
}
