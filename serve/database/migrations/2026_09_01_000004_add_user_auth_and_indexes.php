<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('status');
            $table->index(['type', 'status']);
            $table->index(['parent_id']);
            $table->index(['vip_id', 'end_at']);
        });

        Schema::table('links', function (Blueprint $table): void {
            $table->index(['user_id', 'status']);
        });

        Schema::table('link_visit_logs', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at']);
            $table->index(['link_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['vip_id', 'end_at']);
            $table->dropColumn('must_change_password');
        });

        Schema::table('links', fn (Blueprint $table) => $table->dropIndex(['user_id', 'status']));

        Schema::table('link_visit_logs', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['link_id', 'created_at']);
        });
    }
};
