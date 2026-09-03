<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedBigInteger('used_uv')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'period_start']);
            $table->index(['user_id', 'period_start', 'period_end']);
        });

        Schema::create('usage_visitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usage_period_id')->constrained('usage_periods')->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamps();
            $table->unique(['usage_period_id', 'visitor_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_visitors');
        Schema::dropIfExists('usage_periods');
    }
};
