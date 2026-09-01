<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            $table->char('target_version', 36)->nullable()->unique()->after('health_status');
        });

        DB::table('links')->select('id')->orderBy('id')->chunkById(100, function ($links): void {
            foreach ($links as $link) {
                DB::table('links')->where('id', $link->id)->update([
                    'target_version' => (string) Str::uuid(),
                ]);
            }
        });

        Schema::table('links', function (Blueprint $table): void {
            $table->char('target_version', 36)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            $table->dropColumn('target_version');
        });
    }
};
