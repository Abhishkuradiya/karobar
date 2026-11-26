<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_accountant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('omegaplus');
            $table->text('api_key');
            $table->string('base_url')->default('https://api.omegaplusapi.com');
            $table->string('model')->default('claude-quantum-lite-1');
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(2048);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_accountant_settings');
    }
};
