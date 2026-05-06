<?php

namespace App\Models;

use App\Enums\ReviewReason;
use App\Models\Concerns\HasOptimisticVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionEntry extends Model
{
    use HasFactory;
    use HasOptimisticVersion;

    protected $fillable = [
        'user_id',
        'scryfall_id',
        'location_id',
        'quantity',
        'condition',
        'foil',
        'notes',
        'review_reason',
        'source_deck_id',
        'source_deck_name_snapshot',
        'source_deck_deleted',
    ];

    protected $casts = [
        'foil' => 'boolean',
        'quantity' => 'integer',
        'review_reason' => ReviewReason::class,
        'source_deck_deleted' => 'boolean',
        'version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The deck this copy was last shrunk/dropped out of, if it currently
     * lives in the user's pending-relocation bucket. NULL once the deck is
     * gone (FK is nullOnDelete) — `source_deck_name_snapshot` keeps the
     * label readable in that case.
     */
    public function sourceDeck(): BelongsTo
    {
        return $this->belongsTo(Deck::class, 'source_deck_id');
    }

    // Card lookup uses scryfall_id as both local and foreign key
    public function card(): BelongsTo
    {
        return $this->belongsTo(ScryfallCard::class, 'scryfall_id', 'scryfall_id');
    }
}
