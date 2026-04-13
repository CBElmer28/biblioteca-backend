<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Copy extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'book_id', 'copy_code', 'condition', 'location', 'status'
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    // Scope para filtrar rápidamente copias disponibles
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}