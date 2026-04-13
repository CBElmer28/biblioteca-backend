<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->text('biography')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->date('death_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void { Schema::dropIfExists('authors'); }
};