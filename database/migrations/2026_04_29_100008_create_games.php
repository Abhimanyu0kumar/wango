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
        Schema::create('games', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('slug', 150)->unique();

            $table->string('engine_key', 100);
            // dice, lucky_draw (only Lucky Draw is supported)

            $table->string('provider', 100)->default('internal');

            $table->decimal('min_bet', 18, 2)->default(1);
            $table->decimal('max_bet', 18, 2)->default(100000);

            $table->json('metadata')->nullable();

            $table->enum('status', ['active', 'inactive', 'maintenance'])
                ->default('active');

            $table->timestamps();

            $table->index('category_id');
            $table->index('slug');
            $table->index('engine_key');
            $table->index('provider');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
