<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hut extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id', 'name', 'country', 'club', 'latitude', 'longitude',
        'altitude', 'total_beds', 'phone', 'website', 'catalog_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'altitude' => 'integer',
            'total_beds' => 'integer',
            'catalog_synced_at' => 'datetime',
        ];
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(HutAvailability::class);
    }

    public function bookingUrl(): string
    {
        return "https://www.hut-reservation.org/reservation/book-hut/{$this->id}/wizard";
    }
}
