<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS penalties');
        DB::statement('SET search_path TO penalties');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS penalties CASCADE');
    }
};