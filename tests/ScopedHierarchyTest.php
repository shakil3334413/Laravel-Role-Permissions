<?php

namespace Shakil\Permissions\Tests;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Shakil\Permissions\Models\Module;
use Shakil\Permissions\Models\Permission;
use Shakil\Permissions\Models\Role;

class ScopedHierarchyTest extends TestCase
{
    private function permission(string $scope = 'web', string $slug = 'orders.view'): Permission
    {
        $module = Module::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);

        return Permission::create([
            'name' => 'View orders', 'slug' => $slug, 'scope' => $scope, 'module_id' => $module->id,
        ]);
    }

    private function role(string $scope = 'web', string $slug = 'editor'): Role
    {
        return Role::create(['name' => 'Editor', 'slug' => $slug, 'scope' => $scope]);
    }

    public function test_a_module_without_submodules_has_direct_permissions(): void
    {
        $permission = $this->permission();
        $module = $permission->module;
        $this->assertNull($permission->submodule);
        $this->assertSame(0, $module->submodules()->count());
        $this->assertSame(1, $module->directPermissions()->count());
    }

    public function test_a_module_can_have_direct_and_submodule_permissions_together(): void
    {
        $direct = $this->permission();
        $module = $direct->module;
        $submodule = $module->submodules()->create(['name' => 'Orders', 'slug' => 'orders']);
        $nested = $submodule->permissions()->create(['name' => 'Create orders', 'slug' => 'orders.create']);
        $this->assertSame($module->id, $nested->module_id);
        $this->assertSame($submodule->id, $nested->submodule_id);
        $this->assertSame(2, $module->permissions()->count());
        $this->assertSame(1, $module->directPermissions()->count());
        $this->assertSame(1, $submodule->permissions()->count());

        $role = $this->role()->givePermissionTo('orders.view')->givePermissionTo('orders.create');
        $user = User::create(['name' => 'Reader'])->assignRole($role->slug);
        $this->assertTrue($user->hasPermissionTo('orders.view'));
        $this->assertTrue($user->hasPermissionTo('orders.create'));
    }

    public function test_database_rejects_submodule_from_a_different_module(): void
    {
        $permission = $this->permission();
        $other = Module::create(['name' => 'Other', 'slug' => 'other']);
        $submodule = $other->submodules()->create(['name' => 'Orders', 'slug' => 'orders']);
        $this->expectException(QueryException::class);
        DB::table('rbac_permissions')->where('id', $permission->id)->update(['submodule_id' => $submodule->id]);
    }

    public function test_web_grants_do_not_authorize_app_even_when_slugs_match(): void
    {
        $this->permission('web');
        $this->permission('app');
        $this->role('web')->givePermissionTo('orders.view');
        $this->role('app')->givePermissionTo('orders.view');
        $user = User::create(['name' => 'Reader'])->assignRole('editor', 'web');
        $this->assertTrue($user->can('permission:web:orders.view'));
        $this->assertFalse($user->can('permission:app:orders.view'));
        $user->assignRole('editor', 'app');
        $this->assertTrue($user->can('permission:app:orders.view'));
        $user->removeRole('editor', 'web');
        $this->assertFalse($user->can('permission:web:orders.view'));
        $this->assertTrue($user->can('permission:app:orders.view'));
    }

    public function test_database_rejects_cross_scope_permission_grants(): void
    {
        $permission = $this->permission('app');
        $role = $this->role('web');
        $this->expectException(QueryException::class);
        DB::table('rbac_permission_role')->insert([
            'permission_id' => $permission->id, 'role_id' => $role->id, 'scope' => 'web',
        ]);
    }

    public function test_helper_cannot_grant_app_only_permission_to_web_role(): void
    {
        $this->permission('app');
        $role = $this->role('web');
        $this->expectException(ModelNotFoundException::class);
        $role->givePermissionTo('orders.view');
    }

    public function test_set_role_replaces_only_the_requested_scope(): void
    {
        $this->role('web', 'editor');
        $this->role('web', 'manager');
        $this->role('app', 'mobile');
        $user = User::create(['name' => 'Reader'])->assignRole('editor')->assignRole('mobile', 'app');
        $user->setRole('manager', 'web');
        $this->assertFalse($user->hasRole('editor'));
        $this->assertTrue($user->hasRole('manager'));
        $this->assertTrue($user->hasRole('mobile', 'app'));
    }

    public function test_multiple_assignment_and_sync_clear_only_selected_scope(): void
    {
        $this->role('web', 'editor');
        $this->role('web', 'manager');
        $this->role('app', 'mobile');
        $user = User::create(['name' => 'Reader'])->assignRoles(['editor', 'manager', 'editor']);
        $user->assignRole('mobile', 'app');
        $this->assertSame(3, $user->roles()->count());
        $user->syncRoles([], 'web');
        $this->assertSame(1, $user->roles()->count());
        $this->assertTrue($user->hasRole('mobile', 'app'));
    }

    public function test_single_mode_replaces_roles_but_keeps_other_scope(): void
    {
        config(['permissions.role_mode' => 'single']);
        $this->role('web', 'editor');
        $this->role('web', 'manager');
        $this->role('app', 'mobile');
        $user = User::create(['name' => 'Reader'])->assignRole('editor')->assignRole('mobile', 'app');
        $user->assignRole('manager');
        $this->assertFalse($user->hasRole('editor'));
        $this->assertTrue($user->hasRole('manager'));
        $this->assertTrue($user->hasRole('mobile', 'app'));
        $this->assertSame(2, $user->roles()->count());
    }

    public function test_single_mode_rejects_multiple_roles(): void
    {
        config(['permissions.role_mode' => 'single']);
        $user = User::create(['name' => 'Reader']);
        $this->expectException(InvalidArgumentException::class);
        $user->assignRoles(['editor', 'manager']);
    }

    public function test_failed_sync_preserves_existing_assignments(): void
    {
        $this->role('web', 'editor');
        $this->role('web', 'manager');
        $user = User::create(['name' => 'Reader'])->assignRole('editor');
        try {
            $user->syncRoles(['manager', 'missing']);
            $this->fail('Expected missing role exception.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($user->hasRole('editor'));
            $this->assertFalse($user->hasRole('manager'));
        }
    }

    public function test_unknown_or_missing_gate_scope_is_denied(): void
    {
        $this->permission();
        $this->role()->givePermissionTo('orders.view');
        $user = User::create(['name' => 'Reader'])->assignRole('editor');
        $this->assertFalse($user->can('permission:orders.view'));
        $this->assertFalse($user->can('permission:unknown:orders.view'));
        $this->assertFalse($user->can('permission:web:'));
        $this->assertFalse($user->hasPermissionTo('orders.view', 'unknown'));
    }

    public function test_invalid_scope_writes_fail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->role('invalid');
    }

    public function test_assigned_role_cannot_change_scope(): void
    {
        $role = $this->role();
        $this->expectException(LogicException::class);
        $role->update(['scope' => 'app']);
    }

    public function test_unsaved_principal_cannot_receive_roles(): void
    {
        $this->role();
        $this->expectException(LogicException::class);
        (new User)->assignRole('editor');
    }

    public function test_http_route_scope_is_enforced(): void
    {
        $this->permission('web');
        $this->permission('app');
        $this->role('web')->givePermissionTo('orders.view');
        Route::middleware(['auth', 'can:permission:web:orders.view'])->get('/web-orders', fn () => 'ok');
        Route::middleware(['auth', 'can:permission:app:orders.view'])->get('/app-orders', fn () => 'ok');
        $user = User::create(['name' => 'Reader'])->assignRole('editor', 'web');
        $this->actingAs($user)->getJson('/web-orders')->assertOk();
        $this->getJson('/app-orders')->assertForbidden();
    }

    public function test_submodule_with_permissions_cannot_be_deleted(): void
    {
        $module = $this->permission()->module;
        $submodule = $module->submodules()->create(['name' => 'Orders', 'slug' => 'orders']);
        $submodule->permissions()->create(['name' => 'Create orders', 'slug' => 'orders.create']);
        $this->expectException(QueryException::class);
        $submodule->delete();
    }
}
