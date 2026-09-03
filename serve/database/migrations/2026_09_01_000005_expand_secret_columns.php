<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mini_programs', function (Blueprint $table): void {
            $table->text('secret')->change();
        });
    }

    public function down(): void
    {
        // Protected ciphertext is intentionally never narrowed during rollback.
    }
};
