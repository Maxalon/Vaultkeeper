<?php

namespace App\Enums;

/**
 * Why a collection_entry needs the user's attention. Stored as a nullable
 * string on `collection_entries.review_reason`; null means the row is in
 * good standing.
 *
 * Each case carries different UX implications on the /review surface:
 *
 *   - NoLocation           — row has no location_id (unassemble, deck delete,
 *                            inline picker shrink). User picks a destination.
 *   - DefaultValuesApplied — assemble minted the row with default condition
 *                            / foil / notes; the user can either accept
 *                            those defaults or correct them.
 *   - CardDataChanged      — Scryfall deleted/migrated the underlying
 *                            scryfall_id; user should rebind to a new
 *                            printing or discard.
 */
enum ReviewReason: string
{
    case NoLocation           = 'no_location';
    case DefaultValuesApplied = 'default_values_applied';
    case CardDataChanged      = 'card_data_changed';
}
