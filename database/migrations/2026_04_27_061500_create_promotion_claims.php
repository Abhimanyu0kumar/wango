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
        Schema::create('promotion_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->string('promotion_code', 64);
            $table->string('status', 16)->default('claimed');
            $table->timestamp('claimed_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();


            $table->unique(['user_id', 'promotion_code']);
            $table->index('promotion_code');
            $table->index('status');
            $table->index('claimed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_claims');
    }
};
