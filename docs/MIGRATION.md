# Migrating existing application data

The initial migration targets a new package installation. It is not an upgrade migration for the blog schema or the earlier unpublished package scaffold.

1. Back up the database and validate the migration in a separate environment.
2. Map existing modules to unique module slugs. Create submodules only where needed.
3. Map old display-name permission checks to stable permission slugs.
4. Explicitly classify each role and permission as web or app. If it applies to both, create separate records in both scopes.
5. Preserve module relationships. Set submodule_id to null for direct permissions; otherwise select a submodule under that same module.
6. Copy role-permission links into the matching scope.
7. Copy each existing users.role_id assignment using setRole(roleSlug, scope). For multi-role data, use syncRoles(roleSlugs, scope).
8. Update routes to can:permission:web:slug or can:permission:app:slug. Add the contract and trait to users.
9. Test authorized and denied web/API requests before switching traffic.
10. Remove obsolete tables/columns only in a separate, reviewed migration after the application no longer uses them.

If your database already has the old rbac_* tables, write an additive upgrade migration for scope columns, submodules, unique keys and composite foreign keys instead of running the new create migration. Preserve IDs and role assignments. No automatic upgrade is provided because the correct scope of existing grants cannot be inferred safely.
