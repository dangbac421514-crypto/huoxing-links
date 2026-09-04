<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
            $table->string('health_error_code', 64)->nullable()->after('health_checked_at');
            $table->index(['health_status', 'health_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            $table->dropIndex(['health_status', 'health_checked_at']);
            $table->dropColumn(['health_checked_at', 'health_error_code']);
        });
    }
};
