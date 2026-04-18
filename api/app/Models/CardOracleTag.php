<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Functional category tag for an oracle-id (e.g. ramp, draw, removal).
 * Sourced from Scryfall's `otag:` queries. Stored at oracle_id level
 * because functional identity is the same across all printings.
 */
class CardOracleTag extends Model
{
    use HasFactory;

    protected $table = 'card_oracle_tags';

    protected $fillable = ['oracle_id', 'tag'];
}
