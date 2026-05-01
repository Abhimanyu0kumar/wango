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
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bet_id')->unique()->constrained('bets');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('result', [
                'won',
                'lost',
                'refunded',
                'partial'
            ]);

            $table->decimal('payout_amount', 18, 2)->default(0);
            $table->decimal('profit_loss', 18, 2)->default(0);

            $table->string('settled_by', 50)->nullable();

            $table->timestamp('settled_at')->useCurrent();

            $table->text('notes')->nullable();

            $table->index('bet_id');
            $table->index('user_id');
            $table->index('result');
            $table->index('settled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
