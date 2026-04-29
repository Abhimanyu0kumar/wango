````php
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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('idempotency_key', 255)->unique();

            $table->text('request_hash')->nullable();

            $table->string('endpoint', 255)->nullable();

            $table->unsignedSmallInteger('response_code')->nullable();

            $table->json('response_body')->nullable();

            $table->enum('status', [
                'processing',
                'completed',
                'failed',
                'expired',
            ])->default('processing');

            $table->timestamp('expires_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};