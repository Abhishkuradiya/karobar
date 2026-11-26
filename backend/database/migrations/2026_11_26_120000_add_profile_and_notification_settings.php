<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('legal_name', 150)->nullable()->after('name');
            $table->string('address', 255)->nullable()->after('city');
            $table->string('postal_code', 12)->nullable()->after('address');
            $table->string('phone', 25)->nullable()->after('postal_code');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('preferences');
            $table->timestamps();
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('notification_key', 100);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'business_id', 'notification_key'], 'notification_reads_user_business_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('user_notification_preferences');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'address', 'postal_code', 'phone']);
        });
    }
};
