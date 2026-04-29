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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->constrained('wallet_accounts')
                ->cascadeOnDelete();

            $table->string('transaction_code', 120)->unique();

            $table->string('source_table', 50)
                ->nullable()
                ->comment('deposits, withdrawals, bets, settlements');

            $table->unsignedBigInteger('source_id')->nullable();

            $table->decimal('amount', 18, 2);

            $table->enum('txn_type', [
                'credit',
                'debit',
            ]);

            $table->enum('status', [
                'pending',
                'success',
                'failed',
                'cancelled',
                'reversed',
            ])->default('success');

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('wallet_id');
            $table->index('txn_type');
            $table->index('status');
            $table->index(['source_table', 'source_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
