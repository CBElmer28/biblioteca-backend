<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Book extends Model
{
    use HasUuids, SoftDeletes, HasSlug;

    protected $fillable = [
        'isbn_13', 'isbn_10',
        'title', 'subtitle', 'synopsis',
        'cover_url', 'publisher',
        'publication_year', 'edition',
        'language', 'pages',
        'dewey_code', 'location_hint',
        'is_digital', 'digital_file_url',
        'total_copies', 'available_copies',
        'is_active','glpi_id',
    ];

    protected function casts(): array
    {
        return [
            'is_digital'       => 'boolean',
            'is_active'        => 'boolean',
            'total_copies'     => 'integer',
            'available_copies' => 'integer',
            'pages'            => 'integer',
            'publication_year' => 'integer',
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function authors(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_author')
                    ->withPivot('role')
                    ->orderByPivot('role');
    }

    public function primaryAuthor(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_author')
                    ->wherePivot('role', 'primary');
    }

    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_category');
    }

    // Solo accesible en libros físicos
    public function copies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Copy::class);
    }

    public function availableCopies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Copy::class)
                    ->where('status', 'available')
                    ->where('is_loanable', true);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw('is_active = true');
    }

    public function scopePhysical(Builder $query): Builder
    {
        return $query->whereRaw('is_digital = false');
    }
    
    public function scopeDigital(Builder $query): Builder
    {
        return $query->whereRaw('is_digital = true');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('available_copies', '>', 0);
    }

    // Búsqueda Full-Text usando el índice GIN con ts_rank para relevancia
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query
            ->whereRaw(
                "search_vector @@ plainto_tsquery('spanish', ?)",
                [$term]
            )
            ->orderByRaw(
                "ts_rank(search_vector, plainto_tsquery('spanish', ?)) DESC",
                [$term]
            );
    }

    // ── Helpers de dominio ────────────────────────────────────────────────────

    /**
     * Recalcula los contadores desnormalizados desde la tabla copies.
     * Debe llamarse tras cualquier cambio de status en un Copy hijo.
     */
    public function recalculateCopyCounts(): void
    {
        // Una sola query agregada — no N+1
        $counts = $this->copies()
            ->selectRaw('COUNT(*) as total, COUNT(*) FILTER (WHERE status = ? AND is_loanable = true) as available', ['available'])
            ->first();

        $this->updateQuietly([
            'total_copies'     => $counts->total ?? 0,
            'available_copies' => $counts->available ?? 0,
        ]);
    }

    public function isPhysical(): bool
    {
        return !$this->is_digital;
    }

    public function hasAvailableCopy(): bool
    {
        return $this->available_copies > 0;
    }

    /**
     * Para e-books: siempre disponible (licencia ilimitada).
     * Para físicos: depende del contador desnormalizado.
     */
    public function isAvailableForLoan(): bool
    {
        return $this->is_digital ? true : $this->hasAvailableCopy();
    }
}