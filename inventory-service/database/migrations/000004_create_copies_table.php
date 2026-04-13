<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('copies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('book_id');
            $table->string('copy_code')->unique(); // Ej. LIB-Fis-001
            $table->string('condition')->default('good'); // good, fair, poor, damaged
            $table->string('location')->nullable(); // Ej. Estante A-3
            
            // Estado exclusivo para el mundo físico
            $table->enum('status', ['available', 'borrowed', 'maintenance', 'lost'])->default('available');
            
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copies');
    }
};