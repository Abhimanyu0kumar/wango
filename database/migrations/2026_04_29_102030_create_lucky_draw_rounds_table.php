<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_draw_rounds', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('game_id')
                ->constrained('games')
                ->cascadeOnDelete();

            $table->foreignId('round_id')
                ->unique()
                ->constrained('game_rounds')
                ->cascadeOnDelete();

            $table->integer('duration_sec')->default(60);
            
            $table->timestamp('betting_starts_at')->nullable();
            $table->timestamp('betting_closes_at')->nullable();
            $table->timestamp('result_at')->nullable();
            
            $table->decimal('small_multiplier', 8, 2)->default(1.90);
            $table->decimal('draw_multiplier', 8, 2)->default(4.50);
            $table->decimal('big_multiplier', 8, 2)->default(1.90);
            
            $table->unsignedTinyInteger('dice_one')->nullable();
            $table->unsignedTinyInteger('dice_two')->nullable();
            $table->unsignedTinyInteger('total')->nullable();
            
            $table->enum('winning_side', ['small', 'big', 'draw'])->nullable();
            
            $table->enum('result_mode', ['automatic', 'manual'])->default('automatic');
            
            $table->foreignId('modified_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            
            $table->timestamp('modified_at')->nullable();
            
            $table->enum('status', [
                'waiting',
                'betting_open',
                'locked',
                'settling',
                'settled',
                'cancelled'
            ])->default('waiting');
            
            $table->decimal('total_bet_amount', 18, 2)->default(0);
            $table->decimal('total_payout_amount', 18, 2)->default(0);
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index('game_id');
            $table->index('duration_sec');
            $table->index('status');
            $table->index('winning_side');
            $table->index('result_mode');
            $table->index('betting_closes_at');
            $table->index('result_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_draw_rounds');
    }
};
