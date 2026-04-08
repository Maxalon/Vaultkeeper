<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $fillable = [
        'scryfall_id',
        'name',
        'set_code',
        'collector_number',
        'image_uri',
        'last_scryfall_sync',
    ];

    protected $casts = [
        'last_scryfall_sync' => 'datetime',
    ];
}
