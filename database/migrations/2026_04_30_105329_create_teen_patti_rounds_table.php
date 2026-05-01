<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teen_patti_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained('game_rounds')->cascadeOnDelete();
            
            // Card storage (JSON of 3 cards)
            $table->json('cards')->nullable(); // e.g., ["A♠", "K♠", "Q♠"]
            
            // Hand evaluation
            $table->string('hand_type', 50)->nullable(); // trail, sequence, color, pair, high_card
            $table->integer('hand_rank')->nullable(); // numeric rank for comparison
            
            // Result
            $table->string('winning_bet_type', 50)->nullable(); // pair_plus, color, sequence, trail
            $table->json('multipliers')->nullable(); // multipliers for each bet type
            
            // Timing
            $table->integer('duration_sec')->default(60);
            $table->timestamp('betting_starts_at')->nullable();
            $table->timestamp('betting_closes_at')->nullable();
            $table->timestamp('result_at')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            // Status
            $table->enum('status', ['waiting', 'betting_open', 'locked', 'settling', 'settled', 'cancelled'])
                  ->default('waiting');
            
            $table->timestamps();
            
            $table->index(['game_id', 'status']);
            $table->index('round_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teen_patti_rounds');
    }
};
