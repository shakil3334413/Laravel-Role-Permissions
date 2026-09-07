<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('rbac_submodules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('module_id')->constrained('rbac_modules')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['module_id', 'slug']);
            $table->unique(['id', 'module_id']);
        });

        Schema::create('rbac_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('scope', 32)->default('web');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['scope', 'slug']);
            $table->unique(['id', 'scope']);
        });

        Schema::create('rbac_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('module_id')->constrained('rbac_modules')->restrictOnDelete();
            $table->unsignedBigInteger('submodule_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('scope', 32)->default('web');
            $table->timestamps();
            $table->unique(['scope', 'slug']);
            $table->unique(['id', 'scope']);
            // A permission cannot reference a submodule from another module.
            $table->foreign(['submodule_id', 'module_id'], 'rbac_permission_submodule_fk')
                ->references(['id', 'module_id'])->on('rbac_submodules')->restrictOnDelete();
        });

        Schema::create('rbac_permission_role', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->string('scope', 32);
            $table->timestamps();
            $table->primary(['permission_id', 'role_id']);
            // Both references share one scope: cross-scope grants are impossible.
            $table->foreign(['permission_id', 'scope'], 'rbac_grant_permission_fk')
                ->references(['id', 'scope'])->on('rbac_permissions')->cascadeOnDelete();
            $table->foreign(['role_id', 'scope'], 'rbac_grant_role_fk')
                ->references(['id', 'scope'])->on('rbac_roles')->cascadeOnDelete();
        });

        Schema::create('rbac_model_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('rbac_roles')->cascadeOnDelete();
            $table->string('model_type');
            // Change this to uuid/ulid before migrating if your users use those IDs.
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_model_role');
        Schema::dropIfExists('rbac_permission_role');
        Schema::dropIfExists('rbac_permissions');
        Schema::dropIfExists('rbac_roles');
        Schema::dropIfExists('rbac_submodules');
        Schema::dropIfExists('rbac_modules');
    }
};
