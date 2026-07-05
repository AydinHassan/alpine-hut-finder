<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HutAvailability extends Model
{
    protected $fillable = [
        'hut_id', 'date', 'free_beds', 'total_beds',
        'hut_status', 'percentage', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'free_beds' => 'integer',
            'total_beds' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function hut(): BelongsTo
    {
        return $this->belongsTo(Hut::class);
    }
}
