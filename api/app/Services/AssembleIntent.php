<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Value object describing what the user picked in AssembleDeckModal:
 *
 *   - `all = true`  → assemble every section present in the deck. The
 *     `sections` field is ignored. Common case: master "All cards are
 *     present" toggle is checked.
 *   - `all = false` → assemble only the sections listed. Sections not
 *     present in the deck are silently dropped.
 *
 * Excludes are *per-quantity* (locked decision 3.3): each carries a count
 * the user wants left out of the assemble. A `qty` strictly between 1 and
 * the slot's full quantity triggers a partial-exclude split.
 */
final class AssembleIntent
{
    private const VALID_SECTIONS = ['main', 'side', 'maybe'];

    /** @var array<int, string> */
    public readonly array $sections;

    /** @var array<string, int>  zone-scoped key → excluded qty */
    private readonly array $excludeIndex;

    /**
     * @param  array<int, string>  $sections
     * @param  array<int, array{scryfall_id: string, zone: string, qty: int}>  $excludes
     */
    public function __construct(
        public readonly bool $all,
        array $sections = [],
        array $excludes = [],
    ) {
        // Normalise sections — drop unknowns, dedupe.
        $sectionSet = [];
        foreach ($sections as $s) {
            $s = strtolower((string) $s);
            if (in_array($s, self::VALID_SECTIONS, true)) {
                $sectionSet[$s] = true;
            }
        }
        $this->sections = array_keys($sectionSet);

        // Build (scryfall_id, zone) → qty index for cheap lookup during
        // assembly. Per locked 3.3 the zone is mandatory because a card
        // can appear in multiple zones (a Bolt in main and side need
        // their own exclude rows).
        $idx = [];
        foreach ($excludes as $row) {
            $sid = (string) ($row['scryfall_id'] ?? '');
            $zone = strtolower((string) ($row['zone'] ?? ''));
            $qty = max(0, (int) ($row['qty'] ?? 0));
            if ($sid === '' || ! in_array($zone, self::VALID_SECTIONS, true) || $qty === 0) {
                continue;
            }
            $idx[$this->indexKey($sid, $zone)] = ($idx[$this->indexKey($sid, $zone)] ?? 0) + $qty;
        }
        $this->excludeIndex = $idx;
    }

    /**
     * Build from the JSON payload validated in DeckAssemblyController.
     *
     * @param  array{all?: bool, sections?: array<int, string>, excludes?: array<int, array{scryfall_id?: string, zone?: string, qty?: int}>}  $payload
     */
    public static function fromArray(array $payload): self
    {
        $all = (bool) ($payload['all'] ?? false);
        $sections = (array) ($payload['sections'] ?? []);
        $excludes = (array) ($payload['excludes'] ?? []);

        if (! $all && empty($sections)) {
            // Nothing selected — make the failure visible rather than
            // silently no-op'ing (which would look like a bug to the user).
            throw ValidationException::withMessages([
                'sections' => ['Pick at least one section, or set "all" to true.'],
            ]);
        }

        return new self(all: $all, sections: $sections, excludes: $excludes);
    }

    public function coversSection(string $zone): bool
    {
        if ($this->all) {
            return true;
        }
        return in_array(strtolower($zone), $this->sections, true);
    }

    /**
     * Excluded count for a given slot, capped at the slot's quantity. If
     * the user excluded more than the slot has (e.g. malformed payload),
     * we treat it as full-exclude rather than blowing up.
     */
    public function excludedFor(string $scryfallId, string $zone, int $slotQuantity): int
    {
        $key = $this->indexKey($scryfallId, strtolower($zone));
        $raw = $this->excludeIndex[$key] ?? 0;
        return min(max(0, $slotQuantity), $raw);
    }

    private function indexKey(string $scryfallId, string $zone): string
    {
        return $scryfallId.'|'.$zone;
    }
}
