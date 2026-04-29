````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_ledger', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained('wallet_accounts')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('txn_type', [
                'deposit',
                'withdraw',
                'bet_debit',
                'payout',
                'refund',
                'bonus',
                'adjustment',
            ]);

            $table->enum('direction', [
                'credit',
                'debit',
            ]);

            $table->decimal('amount', 18, 2);

            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);

            $table->string('reference_type', 50)->nullable();
            // deposit, withdrawal, bet, settlement, admin

            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('description')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('wallet_id');
            $table->index('user_id');
            $table->index('txn_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger');
    }
};