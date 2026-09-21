<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Approved ACI bank/payment details shown to payers —
     * docs/database/06_PAYMENT_SCHEMA.md §2. `currency` has no default (OD-01)
     * and no rows are created here.
     */
    public function up(): void
    {
        Schema::create('payment_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('bank_name', 150);
            $table->string('account_name', 150);
            $table->string('account_number', 50);
            $table->string('branch', 150)->nullable();
            $table->string('sort_code', 20)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('swift_bic', 11)->nullable();
            $table->char('currency', 3);
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('active_currency_key', 3)->nullable()
                ->virtualAs('IF(is_active = 1, currency, NULL)');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('active_currency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_bank_accounts');
    }
};
