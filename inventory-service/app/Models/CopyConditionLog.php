<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CopyConditionLog extends Model
{
    use HasUuids;

    // Tabla de auditoría: solo inserts, nunca updates
    public $timestamps = false;

    protected $fillable = [
        'copy_id',
        'from_condition', 'to_condition',
        'from_status',    'to_status',
        'changed_by',     'changed_by_name',
        'context',        'loan_id',
        'notes',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function copy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Copy::class);
    }
}