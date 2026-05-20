<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Canonical Scryfall card reference. One row per printing per language,
 * synced from the All Cards bulk file by BulkSyncService. Each language
 * of a printing has its own scryfall_id; the `language` column carries
 * Scryfall's `lang` field verbatim ('en', 'ja', 'de', …) so import
 * flows can prefer English while still resolving a non-English UUID
 * supplied by Archidekt/Moxfield.
 *
 * collection_entries.scryfall_id and deck_entries.scryfall_id FK here.
 *
 * Oracle-invariant fields (cmc, colors, color_identity, type_line,
 * supertypes/types/subtypes, oracle_text, power/toughness/loyalty,
 * legalities, keywords, edhrec_rank, reserved, mana_cost) live on
 * scryfall_oracles and are exposed here via accessors so existing
 * `$card->cmc` style reads keep working. See issue #33.
 */
class ScryfallCard extends Model
{
    use HasFactory;

    protected $table = 'scryfall_cards';

    protected $fillable = [
        'scryfall_id',
        'oracle_id',
        'name',
        'set_code',
        'collector_number',
        'language',
        'rarity',
        'layout',
        'is_dfc',
        'produced_mana',
        'finishes',
        'image_small',
        'image_normal',
        'image_large',
        'image_small_back',
        'image_normal_back',
        'image_large_back',
        'mana_cost_back',
        'type_line_back',
        'oracle_text_back',
        'commander_game_changer',
        'partner_scope',
        'released_at',
        'promo',
        'variation',
        'set_type',
        'oversized',
        'is_default_eligible',
        'is_playtest',
        'printed_text',
        'printed_text_back',
        'last_synced_at',
    ];

    protected $casts = [
        'is_dfc' => 'boolean',
        'commander_game_changer' => 'boolean',
        'promo' => 'boolean',
        'variation' => 'boolean',
        'oversized' => 'boolean',
        'is_default_eligible' => 'boolean',
        'is_playtest' => 'boolean',
        'produced_mana' => 'array',
        'finishes' => 'array',
        'released_at' => 'date',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Always load the paired oracle row — the accessors below depend on
     * it and there's a 1:1 invariant (every printing has an oracle).
     */
    protected $with = ['oracle'];

    /**
     * Surface oracle-invariant fields in toArray() / toJson() output as
     * if they were native columns. Avoids breaking any consumer that
     * iterates the model's serialized form. The auto-eager-load above
     * keeps this from going N+1.
     */
    protected $appends = [
        'mana_cost', 'cmc', 'colors', 'color_identity',
        'type_line', 'supertypes', 'types', 'subtypes',
        'oracle_text', 'power', 'toughness', 'loyalty',
        'legalities', 'keywords', 'edhrec_rank', 'reserved',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(MtgSet::class, 'set_code', 'code');
    }

    public function oracle(): BelongsTo
    {
        return $this->belongsTo(ScryfallOracle::class, 'oracle_id', 'oracle_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CardOracleTag::class, 'oracle_id', 'oracle_id');
    }

    public function raw(): HasOne
    {
        return $this->hasOne(ScryfallCardRaw::class, 'scryfall_id', 'scryfall_id');
    }

    public function priceRow(): HasOne
    {
        return $this->hasOne(CardPrice::class, 'scryfall_id', 'scryfall_id');
    }

    public function getRouteKeyName(): string
    {
        // /api/scryfall-cards/{scryfallCard} resolves by scryfall_id (UUID),
        // not the surrogate auto-increment id.
        return 'scryfall_id';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Oracle-invariant accessors (proxy to scryfall_oracles).
    // ─────────────────────────────────────────────────────────────────────

    public function getManaCostAttribute(): ?string
    {
        return $this->oracle?->mana_cost;
    }

    public function getCmcAttribute(): ?float
    {
        $v = $this->oracle?->cmc;

        return $v === null ? null : (float) $v;
    }

    public function getColorsAttribute(): ?array
    {
        return $this->oracle?->colors;
    }

    public function getColorIdentityAttribute(): ?array
    {
        return $this->oracle?->color_identity;
    }

    public function getTypeLineAttribute(): ?string
    {
        return $this->oracle?->type_line;
    }

    public function getSupertypesAttribute(): ?array
    {
        return $this->oracle?->supertypes;
    }

    public function getTypesAttribute(): ?array
    {
        return $this->oracle?->types;
    }

    public function getSubtypesAttribute(): ?array
    {
        return $this->oracle?->subtypes;
    }

    public function getOracleTextAttribute(): ?string
    {
        return $this->oracle?->oracle_text;
    }

    public function getPowerAttribute(): ?string
    {
        return $this->oracle?->power;
    }

    public function getToughnessAttribute(): ?string
    {
        return $this->oracle?->toughness;
    }

    public function getLoyaltyAttribute(): ?string
    {
        return $this->oracle?->loyalty;
    }

    public function getLegalitiesAttribute(): ?array
    {
        return $this->oracle?->legalities;
    }

    public function getKeywordsAttribute(): ?array
    {
        return $this->oracle?->keywords;
    }

    public function getEdhrecRankAttribute(): ?int
    {
        $v = $this->oracle?->edhrec_rank;

        return $v === null ? null : (int) $v;
    }

    public function getReservedAttribute(): bool
    {
        return (bool) ($this->oracle?->reserved ?? false);
    }
}
