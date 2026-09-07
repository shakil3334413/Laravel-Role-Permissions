<?php

namespace Shakil\Permissions\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Shakil\Permissions\Models\Module;
use Shakil\Permissions\Models\Permission;
use Shakil\Permissions\Models\Role;

class PermissionsTest extends TestCase
{
    private function grant(): User
    {
        $module = Module::create(['name' => 'Users', 'slug' => 'users']);
        Permission::create(['name' => 'View users', 'slug' => 'users.view', 'module_id' => $module->id]);
        Role::create(['name' => 'Editor', 'slug' => 'editor'])->givePermissionTo('users.view');

        return User::create(['name' => 'Shakil'])->assignRole('editor');
    }

    public function test_unknown_permissions_and_users_without_roles_are_denied(): void
    {
        $user = User::create(['name' => 'Reader']);
        $this->assertFalse($user->hasPermissionTo('users.view'));
        $this->assertFalse(Gate::forUser($user)->allows('permission:web:unknown'));
        $this->assertFalse((new User)->hasPermissionTo('users.view'));
    }

    public function test_grants_are_idempotent_and_use_slugs_instead_of_display_names(): void
    {
        $user = $this->grant();
        $user->assignRole('editor');
        Role::first()->givePermissionTo('users.view');
        Permission::first()->update(['name' => 'A translated display name']);

        $this->assertSame(1, DB::table('rbac_model_role')->count());
        $this->assertSame(1, DB::table('rbac_permission_role')->count());
        $this->assertTrue($user->can('permission:web:users.view'));
        $this->assertFalse($user->hasPermissionTo('View users'));
    }

    public function test_revocation_takes_effect_even_with_loaded_relations(): void
    {
        $user = $this->grant();
        $user->load('roles.permissions');
        $this->assertTrue($user->hasPermissionTo('users.view'));
        Role::first()->revokePermissionTo('users.view');
        $this->assertFalse($user->hasPermissionTo('users.view'));
    }

    public function test_inactive_and_removed_roles_do_not_grant_access(): void
    {
        $user = $this->grant();
        $role = Role::first();
        $role->update(['is_active' => false]);
        $this->assertFalse($user->hasPermissionTo('users.view'));
        $role->update(['is_active' => true]);
        $this->assertTrue($user->hasPermissionTo('users.view'));
        $user->removeRole('editor');
        $this->assertFalse($user->hasPermissionTo('users.view'));
    }

    public function test_multiple_roles_combine_permissions_without_sharing_user_grants(): void
    {
        $user = $this->grant();
        Permission::create(['name' => 'Delete', 'slug' => 'users.delete', 'module_id' => Module::first()->id]);
        Role::create(['name' => 'Manager', 'slug' => 'manager'])->givePermissionTo('users.delete');
        $user->assignRole('manager');
        $other = User::create(['name' => 'Other']);

        $this->assertTrue($user->hasPermissionTo('users.view'));
        $this->assertTrue($user->hasPermissionTo('users.delete'));
        $this->assertFalse($other->hasPermissionTo('users.delete'));
    }

    public function test_package_does_not_override_application_gates_or_model_checks(): void
    {
        $user = $this->grant();
        Gate::define('application.allow', fn ($user) => true);
        Gate::define('application.deny', fn ($user) => false);
        $this->assertTrue($user->can('application.allow'));
        $this->assertFalse($user->can('application.deny'));
        $this->assertFalse($user->can('permission:web:users.view', [$user]));
    }

    public function test_routes_return_401_for_guests_403_for_denied_and_200_for_granted(): void
    {
        Route::middleware(['auth', 'can:permission:web:users.view'])
            ->get('/protected', fn () => response()->json(['ok' => true]));

        $this->getJson('/protected')->assertUnauthorized();
        $this->actingAs(User::create(['name' => 'Denied']))->getJson('/protected')->assertForbidden();
        $this->actingAs($this->grant())->getJson('/protected')->assertOk();
    }

    public function test_deleting_roles_cascades_pivots(): void
    {
        $user = $this->grant();
        Role::first()->delete();
        $this->assertFalse($user->hasPermissionTo('users.view'));
        $this->assertSame(0, DB::table('rbac_model_role')->count());
        $this->assertSame(0, DB::table('rbac_permission_role')->count());
    }

    public function test_deleting_user_cleans_polymorphic_assignments(): void
    {
        $this->grant()->delete();
        $this->assertSame(0, DB::table('rbac_model_role')->count());
    }

    public function test_permission_slugs_are_unique(): void
    {
        $this->grant();
        $this->expectException(QueryException::class);
        Permission::create(['name' => 'Duplicate', 'slug' => 'users.view', 'module_id' => Module::first()->id]);
    }

    public function test_unknown_role_assignment_fails_without_creating_a_role(): void
    {
        $user = User::create(['name' => 'Reader']);
        $this->expectException(ModelNotFoundException::class);
        $user->assignRole('missing');
    }

    public function test_unknown_permission_assignment_fails(): void
    {
        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $this->expectException(ModelNotFoundException::class);
        $role->givePermissionTo('missing');
    }

    public function test_duplicate_assignments_are_rejected_at_database_level(): void
    {
        $user = $this->grant();
        $this->expectException(QueryException::class);
        DB::table('rbac_model_role')->insert([
            'role_id' => Role::first()->id,
            'model_id' => $user->id,
            'model_type' => $user->getMorphClass(),
        ]);
    }

    public function test_deleting_a_permission_revokes_access_and_cleans_pivot(): void
    {
        $user = $this->grant();
        Permission::first()->delete();
        $this->assertFalse($user->hasPermissionTo('users.view'));
        $this->assertSame(0, DB::table('rbac_permission_role')->count());
    }

    public function test_module_with_permissions_cannot_be_deleted(): void
    {
        $this->grant();
        $this->expectException(QueryException::class);
        Module::first()->delete();
    }

    public function test_migration_can_roll_back(): void
    {
        $this->grant();
        $migration = require __DIR__.'/../database/migrations/2026_09_08_000000_create_rbac_tables.php';
        $migration->down();
        foreach (['rbac_modules', 'rbac_submodules', 'rbac_roles', 'rbac_permissions', 'rbac_permission_role', 'rbac_model_role'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
