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
        'image_small',
        'image_normal',
        'image_large',
        'image_small_back',
        'image_normal_back',
        'image_large_back',
        'oracle_text',
        'mana_cost',
        'type_line',
        'power',
        'toughness',
        'loyalty',
        'oracle_text_back',
        'mana_cost_back',
        'type_line_back',
        'rarity',
        'colors',
        'is_dfc',
        'legalities',
        'last_scryfall_sync',
    ];

    protected $casts = [
        'last_scryfall_sync' => 'datetime',
        'legalities'         => 'array',
        'colors'             => 'array',
        'is_dfc'             => 'boolean',
    ];
}
