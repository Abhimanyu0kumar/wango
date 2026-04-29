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
        Schema::create('bets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallet_accounts');
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained('game_rounds');

            $table->string('bet_code', 120)->unique();

            $table->decimal('amount', 18, 2);
            $table->decimal('odds', 12, 4)->nullable();

            $table->json('selection');
            // over 50, player A, number 12 etc

            $table->decimal('potential_win', 18, 2)->nullable();
            $table->decimal('payout_amount', 18, 2)->default(0);

            $table->enum('status', [
                'placed',
                'won',
                'lost',
                'refunded',
                'cashout',
                'cancelled'
            ])->default('placed');

            $table->timestamp('placed_at')->useCurrent();
            $table->timestamp('settled_at')->nullable();

            $table->json('metadata')->nullable();

            $table->index('user_id');
            $table->index('wallet_id');
            $table->index('game_id');
            $table->index('round_id');
            $table->index('bet_code');
            $table->index('status');
            $table->index('placed_at');
            $table->index('settled_at');
            $table->index([ 'user_id', 'round_id' ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
