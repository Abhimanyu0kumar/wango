<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poker_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained('game_rounds')->cascadeOnDelete();
            
            // Community cards (flop, turn, river)
            $table->json('community_cards')->nullable(); // ["A♠", "K♥", "Q♦", "J♣", "10♠"]
            
            // Poker round state
            $table->enum('status', [
                'waiting', 'pre_flop', 'flop', 'turn', 'river', 
                'showdown', 'settling', 'settled', 'cancelled'
            ])->default('waiting');
            
            // Timing
            $table->integer('duration_sec')->default(120);
            $table->timestamp('betting_starts_at')->nullable();
            $table->timestamp('betting_closes_at')->nullable();
            $table->timestamp('flop_at')->nullable();
            $table->timestamp('turn_at')->nullable();
            $table->timestamp('river_at')->nullable();
            $table->timestamp('showdown_at')->nullable();
            
            // Metadata for hand evaluation results
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index(['game_id', 'status']);
            $table->index('round_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poker_rounds');
    }
};
