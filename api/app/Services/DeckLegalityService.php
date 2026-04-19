<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\DeckEntry;
use App\Models\ScryfallCard;
use Illuminate\Support\Collection;

/**
 * Stateless. check($deck) recomputes every illegality on demand; the
 * controller diffs the result against persisted deck_ignored_illegalities
 * to mark each entry as "ignored" on the way out.
 *
 * Every returned illegality is an associative array:
 *   type, scryfall_id_1, scryfall_id_2, oracle_id,
 *   expected_count, message, card_name.
 */
class DeckLegalityService
{
    private const FORMATS_WITH_COMMANDERS = ['commander', 'oathbreaker'];

    /** Required main-zone size per format (commanders + signature spells count here). */
    private const MAIN_SIZE = [
        'commander'   => 100,
        'oathbreaker' => 60,
        'pauper'      => 60,
        'modern'      => 60,
        'standard'    => 60,
    ];

    /** Max copies of a single oracle_id in main + side (before exemptions). */
    private const MAX_COPIES = [
        'commander'   => 1,
        'oathbreaker' => 1,
        'pauper'      => 4,
        'modern'      => 4,
        'standard'    => 4,
    ];

    /** Cards with a hard-capped higher-than-normal limit. */
    private const NAMED_COPY_EXCEPTIONS = [
        'Nazgûl'         => 9,
        'Seven Dwarves' => 7,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function check(Deck $deck): array
    {
        $deck->loadMissing(['entries.card', 'commander1', 'commander2']);

        $illegalities = [];

        $this->checkDeckSize($deck, $illegalities);
        $this->checkFormatLegality($deck, $illegalities);
        $this->checkDuplicates($deck, $illegalities);
        $this->checkColorIdentity($deck, $illegalities);
        $this->checkCommanderValidity($deck, $illegalities);
        $this->checkOathbreakerValidity($deck, $illegalities);

        return $illegalities;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deck size
    // ─────────────────────────────────────────────────────────────────────

    private function checkDeckSize(Deck $deck, array &$out): void
    {
        $required = self::MAIN_SIZE[$deck->format] ?? null;
        if ($required === null) {
            return;
        }

        $mainCount = $deck->entries->where('zone', 'main')->sum('quantity');

        if ($mainCount !== $required) {
            $out[] = $this->illegality(
                'deck_size',
                expectedCount: $required,
                message: "Main deck has {$mainCount} card(s); {$deck->format} requires {$required}.",
            );
        }

        // Standard/Modern/Pauper require sideboard of exactly 0 or 15.
        if (in_array($deck->format, ['standard', 'modern', 'pauper'], true)) {
            $sideCount = $deck->entries->where('zone', 'side')->sum('quantity');
            if ($sideCount !== 0 && $sideCount !== 15) {
                $out[] = $this->illegality(
                    'too_many_cards',
                    expectedCount: 15,
                    message: "Sideboard has {$sideCount} card(s); {$deck->format} requires 0 or 15.",
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Format legality per card
    // ─────────────────────────────────────────────────────────────────────

    private function checkFormatLegality(Deck $deck, array &$out): void
    {
        foreach ($deck->entries as $entry) {
            if (! in_array($entry->zone, ['main', 'side'], true)) {
                continue;
            }
            $card = $entry->card;
            if ($card === null) {
                continue;
            }

            $status = $card->legalities[$deck->format] ?? null;
            if ($status === null || $status === 'legal') {
                continue;
            }

            $type = $status === 'banned' ? 'banned_card' : 'not_legal_in_format';

            $out[] = $this->illegality(
                $type,
                scryfallId1: $card->scryfall_id,
                message: "{$card->name} is {$status} in {$deck->format}.",
                cardName: $card->name,
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Duplicate cards (oracle_id bucketing)
    // ─────────────────────────────────────────────────────────────────────

    private function checkDuplicates(Deck $deck, array &$out): void
    {
        $cap = self::MAX_COPIES[$deck->format] ?? null;
        if ($cap === null) {
            return;
        }

        $byOracle = $deck->entries
            ->whereIn('zone', ['main', 'side'])
            ->filter(fn (DeckEntry $e) => $e->card !== null)
            ->groupBy(fn (DeckEntry $e) => $e->card->oracle_id);

        foreach ($byOracle as $oracleId => $entries) {
            $total = $entries->sum('quantity');
            $card  = $entries->first()->card;

            $allowed = $this->allowedCopies($card, $cap);
            if ($total <= $allowed) {
                continue;
            }

            $out[] = $this->illegality(
                'duplicate_card',
                oracleId: $oracleId,
                expectedCount: $allowed,
                message: "{$card->name} appears {$total} time(s); {$deck->format} allows at most {$allowed}.",
                cardName: $card->name,
            );
        }
    }

    private function allowedCopies(ScryfallCard $card, int $cap): int
    {
        if ($this->isBasicLand($card)) {
            return PHP_INT_MAX;
        }

        $oracle = $card->oracle_text ?? '';
        if (str_contains($oracle, 'A deck can have any number of cards named')) {
            return PHP_INT_MAX;
        }

        if (isset(self::NAMED_COPY_EXCEPTIONS[$card->name])) {
            return self::NAMED_COPY_EXCEPTIONS[$card->name];
        }

        return $cap;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Color identity (commander + oathbreaker)
    // ─────────────────────────────────────────────────────────────────────

    private function checkColorIdentity(Deck $deck, array &$out): void
    {
        if (! in_array($deck->format, self::FORMATS_WITH_COMMANDERS, true)) {
            return;
        }

        $deckIdentity = $this->normalizeColors(
            array_merge(
                (array) ($deck->commander1->color_identity ?? []),
                (array) ($deck->commander2->color_identity ?? []),
            ),
        );

        foreach ($deck->entries as $entry) {
            if ($entry->zone !== 'main') {
                continue;
            }
            $card = $entry->card;
            if ($card === null) {
                continue;
            }
            // Commanders themselves contribute to identity, no violation.
            if ($entry->is_commander) {
                continue;
            }
            // Lands are exempt from color identity violations — they rarely
            // have a color identity outside their produced mana anyway.
            if ($this->hasType($card, 'Land')) {
                continue;
            }

            $cardIdentity = $this->normalizeColors((array) ($card->color_identity ?? []));
            $over = array_diff($cardIdentity, $deckIdentity);
            if (empty($over)) {
                continue;
            }

            $out[] = $this->illegality(
                'color_identity_violation',
                scryfallId1: $card->scryfall_id,
                message: sprintf(
                    "%s has colors {%s} outside the deck's identity {%s}.",
                    $card->name,
                    implode('', $cardIdentity),
                    implode('', $deckIdentity),
                ),
                cardName: $card->name,
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Commander validity
    // ─────────────────────────────────────────────────────────────────────

    private function checkCommanderValidity(Deck $deck, array &$out): void
    {
        if ($deck->format !== 'commander') {
            return;
        }

        $c1 = $deck->commander1;
        $c2 = $deck->commander2;

        if ($c1 === null) {
            $out[] = $this->illegality(
                'invalid_commander',
                message: 'No commander set for this deck.',
            );
            return;
        }

        foreach (array_filter([$c1, $c2]) as $commander) {
            if (! $this->canBeCommander($commander)) {
                $out[] = $this->illegality(
                    'invalid_commander',
                    scryfallId1: $commander->scryfall_id,
                    message: "{$commander->name} is not a legal commander (must be a legendary creature or have \"can be your commander\").",
                    cardName: $commander->name,
                );
            }
        }

        if ($c1 && $c2 && ! $this->arePartners($c1, $c2)) {
            $out[] = $this->illegality(
                'invalid_partner',
                scryfallId1: $c1->scryfall_id,
                scryfallId2: $c2->scryfall_id,
                message: "{$c1->name} and {$c2->name} cannot be paired as commanders.",
                cardName: $c1->name,
            );
        }
    }

    private function canBeCommander(ScryfallCard $card): bool
    {
        $type = $card->type_line ?? '';
        if (str_contains($type, 'Legendary') && str_contains($type, 'Creature')) {
            return true;
        }
        if (str_contains($card->oracle_text ?? '', 'can be your commander')) {
            return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Oathbreaker validity
    // ─────────────────────────────────────────────────────────────────────

    private function checkOathbreakerValidity(Deck $deck, array &$out): void
    {
        if ($deck->format !== 'oathbreaker') {
            return;
        }

        $c1 = $deck->commander1;
        $c2 = $deck->commander2;

        if ($c1 === null) {
            $out[] = $this->illegality(
                'invalid_commander',
                message: 'No oathbreaker set for this deck.',
            );
        } else {
            foreach (array_filter([$c1, $c2]) as $ob) {
                if (! $this->hasType($ob, 'Planeswalker') || ! str_contains($ob->type_line ?? '', 'Legendary')) {
                    $out[] = $this->illegality(
                        'invalid_commander',
                        scryfallId1: $ob->scryfall_id,
                        message: "{$ob->name} is not a legal oathbreaker (must be a legendary planeswalker).",
                        cardName: $ob->name,
                    );
                }
            }

            if ($c2 && ! $this->arePartners($c1, $c2)) {
                $out[] = $this->illegality(
                    'invalid_partner',
                    scryfallId1: $c1->scryfall_id,
                    scryfallId2: $c2->scryfall_id,
                    message: "{$c1->name} and {$c2->name} cannot be paired as oathbreakers.",
                    cardName: $c1->name,
                );
            }
        }

        // Signature-spell checks run regardless of whether commanders are set —
        // an orphan spell is worth flagging even when the oathbreaker slot is
        // empty, so the user sees the full state of the deck.
        // Each oathbreaker needs exactly one signature spell.
        $oathbreakerEntries = $deck->entries
            ->where('is_commander', true)
            ->values();

        $spells = $deck->entries->where('is_signature_spell', true)->values();

        // Orphan spells: no signature_for_entry_id, or pointing at a missing/
        // non-commander entry.
        $validParentIds = $oathbreakerEntries->pluck('id')->all();
        foreach ($spells as $spell) {
            $parentId = $spell->signature_for_entry_id;
            if ($parentId === null || ! in_array($parentId, $validParentIds, true)) {
                $out[] = $this->illegality(
                    'orphan_signature_spell',
                    scryfallId1: $spell->card?->scryfall_id,
                    message: ($spell->card?->name ?? 'A signature spell')
                        .' is not attached to an oathbreaker in this deck.',
                    cardName: $spell->card?->name,
                );
            } else {
                $this->validateSignatureSpell($spell, $oathbreakerEntries->firstWhere('id', $parentId), $out);
            }
        }

        // Missing spells: each oathbreaker without a matching spell.
        foreach ($oathbreakerEntries as $ob) {
            $matching = $spells->firstWhere('signature_for_entry_id', $ob->id);
            if ($matching === null) {
                $out[] = $this->illegality(
                    'missing_signature_spell',
                    scryfallId1: $ob->card?->scryfall_id,
                    message: ($ob->card?->name ?? 'An oathbreaker')
                        .' has no signature spell assigned.',
                    cardName: $ob->card?->name,
                );
            }
        }
    }

    private function validateSignatureSpell(DeckEntry $spell, DeckEntry $oathbreaker, array &$out): void
    {
        $card = $spell->card;
        $ob   = $oathbreaker->card;
        if ($card === null || $ob === null) {
            return;
        }

        if (! $this->hasType($card, 'Instant') && ! $this->hasType($card, 'Sorcery')) {
            $out[] = $this->illegality(
                'orphan_signature_spell',
                scryfallId1: $card->scryfall_id,
                message: "{$card->name} is not a legal signature spell (must be an instant or sorcery).",
                cardName: $card->name,
            );
            return;
        }

        $spellIdentity = $this->normalizeColors((array) ($card->color_identity ?? []));
        $obIdentity    = $this->normalizeColors((array) ($ob->color_identity ?? []));
        $over = array_diff($spellIdentity, $obIdentity);
        if (! empty($over)) {
            $out[] = $this->illegality(
                'color_identity_violation',
                scryfallId1: $card->scryfall_id,
                scryfallId2: $ob->scryfall_id,
                message: sprintf(
                    "%s has colors {%s} outside %s's identity {%s}.",
                    $card->name,
                    implode('', $spellIdentity),
                    $ob->name,
                    implode('', $obIdentity),
                ),
                cardName: $card->name,
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Partner pairing
    // ─────────────────────────────────────────────────────────────────────

    /** Public so DeckController can reuse it when swapping commander slots. */
    public function arePartners(ScryfallCard $c1, ScryfallCard $c2): bool
    {
        // 1. Symmetric: same partner_scope bucket. Captures plain Partner,
        //    Partner—Friends forever, Partner—Survivors, Partner—Character
        //    select, plus any future "Partner—Foo" BulkSyncService picks up.
        if ($c1->partner_scope !== null
            && $c2->partner_scope !== null
            && $c1->partner_scope === $c2->partner_scope) {
            return true;
        }

        // 2. Asymmetric: Doctor's companion ↔ Doctor subtype.
        if (in_array("Doctor's companion", (array) $c1->keywords, true)
            && $this->hasSubtype($c2, 'Doctor')) {
            return true;
        }
        if (in_array("Doctor's companion", (array) $c2->keywords, true)
            && $this->hasSubtype($c1, 'Doctor')) {
            return true;
        }

        // 3. Asymmetric: Choose a background keyword ↔ Background subtype.
        if (in_array('Choose a background', (array) $c1->keywords, true)
            && $this->hasSubtype($c2, 'Background')) {
            return true;
        }
        if (in_array('Choose a background', (array) $c2->keywords, true)
            && $this->hasSubtype($c1, 'Background')) {
            return true;
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Split type_line on em-dash and match a subtype token exactly.
     * Scryfall's type_line: "Legendary Creature — Time Lord Doctor".
     */
    private function hasSubtype(ScryfallCard $card, string $needle): bool
    {
        $parts = preg_split('/\x{2014}/u', $card->type_line ?? '', 2);
        if (count($parts) < 2) {
            return false;
        }
        $subtypes = preg_split('/\s+/', trim($parts[1]));
        return in_array($needle, $subtypes, true);
    }

    /** Match a card type (the portion before the em-dash). */
    private function hasType(ScryfallCard $card, string $type): bool
    {
        $parts = preg_split('/\x{2014}/u', $card->type_line ?? '', 2);
        return str_contains(trim($parts[0] ?? ''), $type);
    }

    private function isBasicLand(ScryfallCard $card): bool
    {
        $parts = preg_split('/\x{2014}/u', $card->type_line ?? '', 2);
        $pre   = trim($parts[0] ?? '');
        return str_contains($pre, 'Basic') && str_contains($pre, 'Land');
    }

    /**
     * Canonical WUBRG ordering. Matches BulkSyncService::canonicaliseColors;
     * kept here as a local helper so the service stays dependency-free.
     *
     * @param  array<int, string>  $colors
     * @return array<int, string>
     */
    private function normalizeColors(array $colors): array
    {
        $order = ['W' => 0, 'U' => 1, 'B' => 2, 'R' => 3, 'G' => 4];
        $upper = array_map('strtoupper', $colors);
        $unique = array_values(array_unique($upper));
        usort($unique, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        return $unique;
    }

    private function illegality(
        string $type,
        ?string $scryfallId1 = null,
        ?string $scryfallId2 = null,
        ?string $oracleId = null,
        ?int $expectedCount = null,
        string $message = '',
        ?string $cardName = null,
    ): array {
        return [
            'type'           => $type,
            'scryfall_id_1'  => $scryfallId1,
            'scryfall_id_2'  => $scryfallId2,
            'oracle_id'      => $oracleId,
            'expected_count' => $expectedCount,
            'message'        => $message,
            'card_name'      => $cardName,
        ];
    }
}
