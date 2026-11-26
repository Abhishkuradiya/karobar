<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_account_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('account_code', 40);
            $table->string('name', 120)->nullable();
            $table->string('group_name', 80)->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'account_code'], 'chart_account_overrides_biz_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_account_overrides');
    }
};
