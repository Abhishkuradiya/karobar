<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->default(0);
            $table->string('description', 255);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['business_id', 'entry_date']);
            $table->index(['business_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        DB::table('journal_entries')->orderBy('id')->chunkById(500, function ($entries): void {
            foreach ($entries as $entry) {
                DB::table('activities')->insert([
                    'user_id' => $entry->user_id,
                    'business_id' => $entry->business_id,
                    'journal_entry_id' => $entry->id,
                    'entry_date' => $entry->entry_date,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                    'description' => $entry->description,
                    'amount' => round((float) DB::table('journal_lines')->where('journal_entry_id', $entry->id)->sum('debit'), 2),
                    'created_at' => $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ]);
            }
        });
    }

    public function down(): void { Schema::dropIfExists('activities'); }
};
