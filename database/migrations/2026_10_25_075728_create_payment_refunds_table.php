<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds performed manually in the PayHere merchant portal (or by bank),
 * recorded by an administrator. Append-only: the original payment row keeps
 * its amount, paid_at and references; only its status moves to
 * `partially_refunded`/`refunded`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->string('refund_reference', 191);
            $table->date('refunded_on');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['payment_id', 'refund_reference']);
            $table->index(['order_id', 'created_at']);
        });

        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
