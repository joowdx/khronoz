<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Grants are centralized so `db:grant` can reapply them after owner-role rotation.
     */
    public function up(): void
    {
        AppRoleGrants::apply();
    }

    /**
     * Grants are intentionally retained because databases may share the role.
     */
    public function down(): void {}
};
