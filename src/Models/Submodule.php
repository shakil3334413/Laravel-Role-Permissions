<?php

namespace Shakil\Permissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submodule extends Model
{
    protected $table = 'rbac_submodules';

    protected $fillable = ['module_id', 'name', 'slug'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function permissions(): HasMany
    {
        // Also populate the parent module when creating through this relation.
        return $this->hasMany(Permission::class)->withAttributes(['module_id' => $this->module_id]);
    }
}
