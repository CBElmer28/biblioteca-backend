<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Author extends Model
{
    use HasUuids, SoftDeletes, HasSlug;

    protected $fillable = [
        'name', 'biography', 'photo_url',
        'nationality', 'birth_date', 'death_date',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(220);
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function books(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_author')
                    ->withPivot('role')
                    ->orderByPivot('role');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        if ($this->birth_date && $this->death_date) {
            return "{$this->name} ({$this->birth_date->year}–{$this->death_date->year})";
        }
        if ($this->birth_date) {
            return "{$this->name} (n. {$this->birth_date->year})";
        }
        return $this->name;
    }
}