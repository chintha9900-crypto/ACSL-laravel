<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The manual-review queue for e-shop payment conflicts that must never be
 * resolved automatically: repeated success notifications, chargebacks,
 * payments arriving after an order closed, signed notifications with a
 * wrong amount/currency, and bank transfers submitted for an order that was
 * paid, cancelled or expired another way.
 *
 * `fingerprint` (unique) makes recording idempotent: a replayed notification
 * adds no second row. Unsigned gateway fields are kept only in `details`,
 * labelled as unverified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('type', 40)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 20)->charset('ascii')->collation('ascii_bin')->default('open');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->char('fingerprint', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_reviews
                ADD CONSTRAINT payment_reviews_type_allowed
                    CHECK (type IN ('duplicate_notification', 'late_payment', 'chargeback', 'amount_mismatch', 'transfer_conflict')),
                ADD CONSTRAINT payment_reviews_status_allowed CHECK (status IN ('open', 'resolved')),
                ADD CONSTRAINT payment_reviews_resolution_consistent
                    CHECK ((status = 'open' AND resolved_at IS NULL AND resolved_by_user_id IS NULL)
                        OR (status = 'resolved' AND resolved_at IS NOT NULL AND resolved_by_user_id IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reviews');
    }
};
