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
     * Provider-independent payment ledger — docs/database/06_PAYMENT_SCHEMA.md §3.
     * A membership payment targets a renewal term; the free introductory term
     * never has a payment row (`amount > 0` is CHECK-enforced).
     *
     * `order_id` references `orders`, which belongs to the e-commerce tranche.
     * The column, its indexes and the "exactly one purpose" CHECK are created as
     * documented; the foreign key is added by the migration that creates `orders`
     * (default NO ACTION, per docs/database/18 A-1).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('membership_term_id')->nullable()->constrained('membership_terms');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('payment_bank_accounts');
            $table->string('gateway', 30);
            $table->string('transaction_reference', 191)->nullable();
            $table->string('idempotency_key', 100);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 30)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->string('payment_url', 2048)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'idempotency_key']);
            $table->index(['gateway', 'transaction_reference']);
            $table->index(['membership_term_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_exactly_one_purpose
                    CHECK ((membership_term_id IS NULL) <> (order_id IS NULL)),
                ADD CONSTRAINT payments_amount_positive CHECK (amount > 0),
                ADD CONSTRAINT payments_status_allowed
                    CHECK (status IN ('pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
