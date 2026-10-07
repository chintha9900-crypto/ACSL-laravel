<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.1 — public Contact form submissions (docs/database/10 §13).
     * `subject` is required (confirmed business decision); `phone` is
     * optional. `status` is an application-level workflow label
     * (new → replied/closed) — no reply tool exists or is planned, so this is
     * a manual admin label only. `handled_by_user_id` is set only by a future
     * admin status action and RESTRICTs user deletion, matching the documented
     * schema.
     */
    public function up(): void
    {
        Schema::create('contact_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 255);
            $table->string('phone', 40)->nullable();
            $table->string('subject', 255);
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE contact_enquiries
                ADD CONSTRAINT contact_enquiries_status_allowed
                    CHECK (status IN ('new', 'replied', 'closed'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiries');
    }
};
