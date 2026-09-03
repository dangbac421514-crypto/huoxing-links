<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_vip_id')->nullable()->constrained('vip_packages')->nullOnDelete();
            $table->foreignId('to_vip_id')->constrained('vip_packages')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('status', 16)->default('pending');
            $table->timestamp('effective_at');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['user_id', 'status', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_changes');
    }
};
