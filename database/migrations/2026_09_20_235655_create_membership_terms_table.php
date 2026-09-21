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
     * One row per validity term (introductory + renewals) —
     * docs/database/04_MEMBERSHIP_SCHEMA.md §9.
     *
     * `membership_plan_id` references `membership_plans`, which belongs to the
     * later membership-operations tranche. The column and its CHECKs are created
     * as documented; the foreign key is added by the migration that creates
     * `membership_plans` (default NO ACTION, per docs/database/18 A-1).
     */
    public function up(): void
    {
        Schema::create('membership_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships');
            $table->unsignedSmallInteger('term_no');
            $table->string('term_kind', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('payment_status', 40)->charset('ascii')->collation('ascii_bin');
            $table->unsignedSmallInteger('duration_months');
            $table->unsignedBigInteger('membership_plan_id')->nullable()->index();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->char('fee_currency', 3)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedBigInteger('open_renewal_key')->nullable()
                ->virtualAs("IF(status = 'pending_payment', membership_id, NULL)");
            $table->timestamps();

            $table->unique(['membership_id', 'term_no']);
            $table->unique('open_renewal_key');
            $table->index(['status', 'expires_on']);
            $table->index(['status', 'payment_status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE membership_terms
                ADD CONSTRAINT membership_terms_term_no_positive CHECK (term_no >= 1),
                ADD CONSTRAINT membership_terms_term_kind_allowed
                    CHECK (term_kind IN ('introductory', 'renewal')),
                ADD CONSTRAINT membership_terms_status_allowed
                    CHECK (status IN ('pending_payment', 'active', 'expired')),
                ADD CONSTRAINT membership_terms_payment_status_allowed
                    CHECK (payment_status IN ('payment_not_required', 'payment_pending', 'payment_confirmation_submitted', 'payment_confirmed', 'payment_rejected')),
                ADD CONSTRAINT membership_terms_duration_positive CHECK (duration_months > 0),
                ADD CONSTRAINT membership_terms_introductory_is_first_term
                    CHECK ((term_kind = 'introductory') = (term_no = 1)),
                ADD CONSTRAINT membership_terms_free_iff_introductory
                    CHECK ((term_kind = 'introductory') = (payment_status = 'payment_not_required')),
                ADD CONSTRAINT membership_terms_fee_and_plan_by_kind
                    CHECK (
                        (term_kind = 'introductory' AND fee_amount IS NULL AND fee_currency IS NULL AND membership_plan_id IS NULL)
                        OR (term_kind = 'renewal' AND fee_amount > 0 AND fee_currency IS NOT NULL AND membership_plan_id IS NOT NULL)
                    ),
                ADD CONSTRAINT membership_terms_dates_match_status
                    CHECK (
                        (status = 'pending_payment' AND starts_on IS NULL AND expires_on IS NULL AND activated_at IS NULL)
                        OR (status IN ('active', 'expired') AND starts_on IS NOT NULL AND expires_on IS NOT NULL AND activated_at IS NOT NULL AND expires_on >= starts_on)
                    ),
                ADD CONSTRAINT membership_terms_valid_only_when_settled
                    CHECK (status = 'pending_payment' OR payment_status IN ('payment_not_required', 'payment_confirmed')),
                ADD CONSTRAINT membership_terms_introductory_never_pending
                    CHECK (status <> 'pending_payment' OR term_kind = 'renewal')
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_terms');
    }
};
