<?php

namespace Shakil\Permissions\Contracts;

interface HasPermissions
{
    public function hasPermissionTo(string $slug, string $scope = 'web'): bool;
}
