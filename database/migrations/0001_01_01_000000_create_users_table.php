<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `users` follows docs/database/03_IDENTITY_SCHEMA.md §2 (single `name`
     * field, nullable password while `pending_setup`, flat `role`, `status`).
     * `password_reset_tokens` and `sessions` keep Laravel's standard shape (§5).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('role', 20)->charset('ascii')->collation('ascii_bin')->default('member');
            $table->string('status', 20)->charset('ascii')->collation('ascii_bin')->default('pending_setup');
            $table->string('phone', 40)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('aviation_occupation', 150)->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('company', 150)->nullable();
            $table->string('linkedin_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamps();

            $table->index('role');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_allowed CHECK (role IN ('member', 'admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_allowed CHECK (status IN ('pending_setup', 'active', 'suspended'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
