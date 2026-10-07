<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RBAC foundation. `name` is the exact value `users.role` stores (see
     * the foreign key added by `..._000006_add_role_foreign_key_to_users_table`)
     * — same length/charset/collation as that column so the key matches.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('label', 60);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
