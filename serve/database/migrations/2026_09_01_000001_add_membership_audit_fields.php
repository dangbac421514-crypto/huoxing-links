<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vip_logs', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('action', 32)->default('legacy')->after('status');
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('vip_logs', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->dropUnique('vip_logs_idempotency_key_unique');
            $table->dropColumn([
                'actor_user_id',
                'action',
                'reason',
                'before_snapshot',
                'after_snapshot',
                'effective_at',
                'idempotency_key',
            ]);
        });
    }
};
