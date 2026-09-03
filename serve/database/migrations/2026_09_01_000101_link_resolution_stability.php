<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            $table->unsignedTinyInteger('manual_status')->default(1)->after('status');
            $table->unsignedTinyInteger('health_status')->default(1)->after('manual_status');
            $table->index(['user_id', 'manual_status']);
        });

        DB::table('links')->update([
            'manual_status' => DB::raw('status'),
            'expired_at' => null,
        ]);

        Schema::table('link_visit_logs', function (Blueprint $table): void {
            $table->char('visitor_hash', 64)->nullable()->after('device_uid');
            $table->char('ip_hash', 64)->nullable()->after('visitor_hash');
            $table->char('user_agent_hash', 64)->nullable()->after('ip_hash');
            $table->index(['link_id', 'visitor_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('link_visit_logs', function (Blueprint $table): void {
            $table->dropIndex(['link_id', 'visitor_hash']);
            $table->dropColumn(['visitor_hash', 'ip_hash', 'user_agent_hash']);
        });

        Schema::table('links', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'manual_status']);
            $table->dropColumn(['manual_status', 'health_status']);
        });
    }
};
