<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Author extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'biography'];

    public function books()
    {
        return $this->belongsToMany(Book::class, 'book_authors');
    }
}