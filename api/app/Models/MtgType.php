<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Scryfall-canonical MTG types. Synced from /catalog/* endpoints at the
 * start of every bulk sync. Used by BulkSyncService::parseTypeLine() to
 * recognise multi-word subtypes (e.g. "Time Lord") before whitespace split.
 */
class MtgType extends Model
{
    protected $table = 'mtg_type_catalog';

    protected $fillable = [
        'category',
        'name',
        'is_multi_word',
    ];

    protected $casts = [
        'is_multi_word' => 'boolean',
    ];

    public const SUBTYPE_CATEGORIES = [
        'creature_subtype',
        'planeswalker_subtype',
        'land_subtype',
        'artifact_subtype',
        'enchantment_subtype',
        'spell_subtype',
    ];
}
