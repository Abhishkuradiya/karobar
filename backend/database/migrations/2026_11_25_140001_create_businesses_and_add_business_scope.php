<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('gst_registration_type', 24)->default('not_registered');
            $table->string('business_type', 40)->default('other');
            $table->string('language', 5)->default('en');
            $table->string('state', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_business_id')->nullable()
                ->constrained('businesses')->nullOnDelete();
        });

        foreach (['customers', 'sales', 'journal_entries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('business_id')->nullable()
                    ->constrained('businesses')->nullOnDelete();
                $table->index('business_id');
            });
        }

        foreach (DB::table('users')->orderBy('id')->get(['id', 'active_business_id']) as $user) {
            $businessId = DB::table('businesses')
                ->where('user_id', $user->id)
                ->value('id');

            if (! $businessId) {
                $hasLegacyRecords = DB::table('customers')->where('user_id', $user->id)->exists()
                    || DB::table('sales')->where('user_id', $user->id)->exists()
                    || DB::table('journal_entries')->where('user_id', $user->id)->exists();

                if ($hasLegacyRecords) {
                    $now = now();
                    $businessId = DB::table('businesses')->insertGetId([
                        'user_id' => $user->id,
                        'name' => 'My Business',
                        'gst_registration_type' => 'not_registered',
                        'business_type' => 'other',
                        'language' => 'en',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if ($businessId) {
                foreach (['customers', 'sales', 'journal_entries'] as $tableName) {
                    DB::table($tableName)
                        ->where('user_id', $user->id)
                        ->whereNull('business_id')
                        ->update(['business_id' => $businessId]);
                }

                if (! $user->active_business_id) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['active_business_id' => $businessId]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['journal_entries', 'sales', 'customers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_business_id');
        });

        Schema::dropIfExists('businesses');
    }
};
