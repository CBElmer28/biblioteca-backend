<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();

            // Auto-referencia con UUID — jerarquía: Literatura → Novela → Novela Histórica
            $table->uuid('parent_id')->nullable();
            $table->foreign('parent_id')
                  ->references('id')->on('categories')
                  ->onDelete('set null');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('parent_id');
            $table->index('is_active');
        });
    }

    public function down(): void { Schema::dropIfExists('categories'); }
};