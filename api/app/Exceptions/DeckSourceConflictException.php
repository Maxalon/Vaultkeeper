<?php

namespace App\Exceptions;

use App\Models\Deck;
use RuntimeException;

/**
 * Thrown by DeckImportService when an import URL resolves to a (source,
 * source_id) the caller has already imported. The controller catches this,
 * presents the existing deck to the user, and lets them choose between
 * Update / Add as new / Cancel.
 */
class DeckSourceConflictException extends RuntimeException
{
    public function __construct(public readonly Deck $existing)
    {
        parent::__construct("Deck already imported (deck #{$existing->id}).");
    }
}
