<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Book extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'isbn', 'title', 'synopsis', 'cover_url', 
        'is_digital', 'digital_file_url', 'publisher', 'year'
    ];

    protected function casts(): array
    {
        return [
            'is_digital' => 'boolean',
            'year' => 'integer',
        ];
    }

    // Relaciones
    public function authors()
    {
        return $this->belongsToMany(Author::class, 'book_authors');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'book_categories');
    }

    public function copies()
    {
        return $this->hasMany(Copy::class);
    }

    // Helpers de Negocio
    public function isPhysical(): bool
    {
        return !$this->is_digital;
    }

    public function getAvailablePhysicalCopies()
    {
        if ($this->is_digital) {
            return 0; // O infinito, dependiendo de cómo lo maneje el Frontend
        }
        return $this->copies()->where('status', 'available')->count();
    }
}