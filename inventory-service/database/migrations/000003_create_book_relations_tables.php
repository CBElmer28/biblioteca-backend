<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('book_authors', function (Blueprint $table) {
            $table->uuid('book_id');
            $table->uuid('author_id');
            
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('authors')->onDelete('cascade');
            $table->primary(['book_id', 'author_id']);
        });

        Schema::create('book_categories', function (Blueprint $table) {
            $table->uuid('book_id');
            $table->uuid('category_id');
            
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->primary(['book_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_categories');
        Schema::dropIfExists('book_authors');
    }
};