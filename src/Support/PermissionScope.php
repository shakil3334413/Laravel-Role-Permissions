<?php

namespace Shakil\Permissions\Support;

use InvalidArgumentException;

final class PermissionScope
{
    public static function valid(string $scope): bool
    {
        return in_array($scope, config('permissions.scopes', ['web', 'app']), true);
    }

    public static function assert(string $scope): void
    {
        if (! self::valid($scope)) {
            throw new InvalidArgumentException("Unknown permission scope: {$scope}");
        }
    }
}
