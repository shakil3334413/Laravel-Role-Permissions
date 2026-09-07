<?php

namespace Shakil\Permissions;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Shakil\Permissions\Contracts\HasPermissions;
use Shakil\Permissions\Support\PermissionScope;

class PermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permissions.php', 'permissions');
    }

    public function boot(Gate $gate): void
    {
        $prefix = config('permissions.gate_prefix');
        $scopes = config('permissions.scopes');

        if (! is_string($prefix) || $prefix === '') {
            throw new InvalidArgumentException('permissions.gate_prefix must be a non-empty string.');
        }

        if (! is_array($scopes) || $scopes === []) {
            throw new InvalidArgumentException('permissions.scopes must be a non-empty array.');
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $scope)) {
                throw new InvalidArgumentException('Scopes must be lowercase identifiers of at most 32 characters.');
            }
        }

        if (! in_array(config('permissions.role_mode'), ['single', 'multiple'], true)) {
            throw new InvalidArgumentException('permissions.role_mode must be single or multiple.');
        }

        // No database reads during boot; artisan migrate works before tables exist.
        $gate->before(function ($user, string $ability, array $arguments = []) use ($prefix): ?bool {
            if (! str_starts_with($ability, $prefix)) {
                return null;
            }

            if ($arguments !== [] || ! $user instanceof HasPermissions) {
                return false;
            }

            // Explicit scope required: permission:web:users.view.
            $parts = explode(':', substr($ability, strlen($prefix)), 2);
            if (count($parts) !== 2 || ! PermissionScope::valid($parts[0]) || $parts[1] === '') {
                return false;
            }

            return $user->hasPermissionTo($parts[1], $parts[0]);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/permissions.php' => config_path('permissions.php'),
            ], 'permissions-config');
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'permissions-migrations');
        }
    }
}
