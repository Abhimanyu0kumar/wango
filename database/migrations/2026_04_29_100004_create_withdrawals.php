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
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->constrained('wallet_accounts')
                ->cascadeOnDelete();

            $table->decimal('amount', 18, 2);

            $table->enum('payout_method', [
                'upi',
                'bank_transfer',
                'crypto',
            ]);

            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('upi_id', 100)->nullable();

            $table->string('gateway_name', 50)->nullable();
            $table->string('gateway_ref_id', 150)->nullable();

            $table->enum('status', [
                'pending',
                'approved',
                'processing',
                'paid',
                'rejected',
                'cancelled',
            ])->default('pending');

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('wallet_id');
            $table->index('status');
            $table->index('gateway_ref_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};