<?php

namespace App\Contracts;

use App\Models\MiniProgram;

interface MiniProgramSchemeGenerator
{
    public function generate(MiniProgram $mini, string $path, string $query): string;
}
