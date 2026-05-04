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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('name', 64)->nullable();
            $table->string('avatar_url', 255)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->tinyInteger('gender')
                ->unsigned()
                ->nullable()
                ->comment('1=Male,2=Female,3=Other');
            $table->char('country_code', 2)->nullable();
            $table->char('preferred_currency', 3)->nullable();
            $table->json('address_data')->nullable();
            $table->tinyInteger('kyc_status')
                ->unsigned()
                ->default(0)
                ->comment('0=not_submitted,1=pending,2=approved,3=rejected');
            $table->timestamps();

            $table->index('name');
            $table->index('country_code');
            $table->index('preferred_currency');
            $table->index('kyc_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
