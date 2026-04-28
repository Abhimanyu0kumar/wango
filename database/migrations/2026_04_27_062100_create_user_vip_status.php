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
        Schema::create('user_vip_status', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('vip_tier_id');
            $table->decimal('points_balance', 18, 2)->default(0);
            $table->decimal('lifetime_points', 18, 2)->default(0);
            $table->timestamp('tier_assigned_at')->nullable();
            $table->timestamp('next_tier_check_at')->nullable();
            $table->timestamps();

            $table->foreign('vip_tier_id')
                ->references('id')
                ->on('vip_tiers')
                ->cascadeOnDelete();
            $table->index('vip_tier_id');
            $table->index('lifetime_points');
            $table->index('next_tier_check_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_vip_status');
    }
};
