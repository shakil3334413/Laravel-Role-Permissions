<?php

namespace Shakil\Permissions\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;
use LogicException;
use Shakil\Permissions\Models\Role;
use Shakil\Permissions\Support\PermissionScope;

trait HasRoles
{
    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'model', 'rbac_model_role')->withTimestamps();
    }

    public function assignRole(string $slug, string $scope = 'web'): static
    {
        return $this->assignRoles([$slug], $scope);
    }

    public function assignRoles(array $slugs, string $scope = 'web'): static
    {
        return $this->writeRoles($slugs, $scope, config('permissions.role_mode') === 'single');
    }

    public function setRole(string $slug, string $scope = 'web'): static
    {
        return $this->syncRoles([$slug], $scope);
    }

    public function syncRoles(array $slugs, string $scope = 'web'): static
    {
        return $this->writeRoles($slugs, $scope, true);
    }

    private function writeRoles(array $slugs, string $scope, bool $replace): static
    {
        PermissionScope::assert($scope);

        if (! $this->exists) {
            throw new LogicException('Save the user before assigning roles.');
        }

        foreach ($slugs as $slug) {
            if (! is_string($slug) || $slug === '') {
                throw new InvalidArgumentException('Role slugs must be non-empty strings.');
            }
        }

        $slugs = array_values(array_unique($slugs));

        if (config('permissions.role_mode') === 'single' && count($slugs) > 1) {
            throw new InvalidArgumentException('Single-role mode allows one role per scope.');
        }

        $this->getConnection()->transaction(function () use ($slugs, $scope, $replace): void {
            // Serialize package assignment operations for this principal.
            $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $ids = [];
            foreach ($slugs as $slug) {
                $ids[] = Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail()->getKey();
            }

            if ($replace) {
                $currentIds = $this->roles()->where('rbac_roles.scope', $scope)->pluck('rbac_roles.id')->all();
                $removeIds = array_diff($currentIds, $ids);
                if ($removeIds !== []) {
                    $this->roles()->detach($removeIds);
                }
            }

            $this->roles()->syncWithoutDetaching($ids);
        });

        $this->unsetRelation('roles');

        return $this;
    }

    public function removeRole(string $slug, string $scope = 'web'): static
    {
        PermissionScope::assert($scope);
        $role = Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
        $this->roles()->detach($role->getKey());
        $this->unsetRelation('roles');

        return $this;
    }

    public function hasRole(string $slug, string $scope = 'web'): bool
    {
        return $this->exists && PermissionScope::valid($scope)
            && $this->roles()->where('rbac_roles.scope', $scope)->where('rbac_roles.slug', $slug)
                ->where('rbac_roles.is_active', true)->exists();
    }

    public function hasPermissionTo(string $slug, string $scope = 'web'): bool
    {
        if (! $this->exists || $slug === '' || ! PermissionScope::valid($scope)) {
            return false;
        }

        return $this->roles()
            ->where('rbac_roles.scope', $scope)
            ->where('rbac_roles.is_active', true)
            ->whereHas('permissions', function (Builder $query) use ($slug, $scope): void {
                $query->where('rbac_permissions.slug', $slug)->where('rbac_permissions.scope', $scope);
            })
            ->exists();
    }

    public static function bootHasRoles(): void
    {
        static::deleted(function ($model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->roles()->detach();
        });
    }
}
