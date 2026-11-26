<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_accountant_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->json('messages');
            $table->timestamps();
            $table->index(['user_id', 'business_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_accountant_conversations');
    }
};
