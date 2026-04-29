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
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->constrained('wallet_accounts')
                ->cascadeOnDelete();

            $table->decimal('amount', 18, 2);

            $table->enum('payment_method', [
                'upi',
                'card',
                'netbanking',
                'crypto',
            ]);

            $table->string('gateway_name', 50)->nullable();
            $table->string('gateway_txn_id', 150)->nullable();
            $table->string('merchant_order_id', 150)->nullable();

            $table->enum('status', [
                'pending',
                'success',
                'failed',
                'cancelled',
            ])->default('pending');

            $table->timestamp('paid_at')->nullable();

            $table->text('failed_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('wallet_id');
            $table->index('status');
            $table->index('gateway_txn_id');
            $table->index('merchant_order_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};