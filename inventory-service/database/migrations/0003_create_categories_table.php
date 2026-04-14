<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // <- Importante para DB::raw

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        // PASO 1: Creamos la tabla y sus columnas (incluyendo parent_id)
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();

            // Auto-referencia con UUID (Solo la columna, sin la restricción todavía)
            $table->uuid('parent_id')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('parent_id');
            $table->index('is_active');
        });

        // PASO 2: Agregamos la llave foránea en una transacción separada
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')
                  ->references('id')->on('categories')
                  ->onDelete('set null');
        });
    }

    public function down(): void 
    { 
        // Al hacer rollback, si borramos la tabla se van sus llaves foráneas automáticamente
        Schema::dropIfExists('categories'); 
    }
};