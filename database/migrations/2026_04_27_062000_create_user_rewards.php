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
        Schema::create('user_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('reward_id')->nullable();
            $table->unsignedBigInteger('promotion_claim_id')->nullable();
            $table->string('status', 16)->default('granted');
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('reward_id')
                ->references('id')
                ->on('rewards')
                ->nullOnDelete();
            $table->foreign('promotion_claim_id')
                ->references('id')
                ->on('promotion_claims')
                ->nullOnDelete();
            $table->unique(['user_id', 'reward_id'], 'user_reward_unique');
            $table->index(['user_id', 'status', 'expires_at']);
            $table->index('status');
            $table->index('granted_at');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_rewards');
    }
};
