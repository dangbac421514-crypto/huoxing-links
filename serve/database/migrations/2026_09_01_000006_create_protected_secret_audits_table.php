<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_secret_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_name', 64);
            $table->string('key_identifier', 191);
            $table->string('source', 64);
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['key_identifier', 'created_at']);
            $table->index(['event_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_secret_audits');
    }
};
