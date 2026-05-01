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
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('game_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('round_code', 120)->unique();

            $table->enum('state', [
                'waiting',
                'betting_open',
                'locked',
                'pre_flop',
                'flop',
                'turn',
                'river',
                'showdown',
                'settling',
                'settled',
                'running',
                'cancelled'
            ])->default('waiting');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('betting_closes_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->decimal('total_bet_amount', 18, 2)->default(0);
            $table->decimal('total_payout_amount', 18, 2)->default(0);

            $table->json('result')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('game_id');
            $table->index('round_code');
            $table->index('state');
            $table->index('starts_at');
            $table->index('ended_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
