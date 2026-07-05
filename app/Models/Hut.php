<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hut extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id', 'source', 'name', 'country', 'club', 'latitude', 'longitude',
        'altitude', 'total_beds', 'phone', 'website', 'booking_url', 'catalog_synced_at',
    ];

    /** Offset for huetten-holiday hut ids so they never collide with HRS hutIds. */
    public const HUETTEN_HOLIDAY_ID_OFFSET = 1_000_000;

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
        return $this->booking_url
            ?? "https://www.hut-reservation.org/reservation/book-hut/{$this->id}/wizard";
    }
}
