<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use App\Models\ScryfallOracle;
use App\Services\BulkSyncService;

/**
 * Parses Scryfall-style search syntax into an Eloquent builder against
 * scryfall_oracles (the denormalised oracle-level table populated by
 * BulkSyncService::syncOracleTable()). Returns {builder, warnings, sort}
 * so the controller can:
 *   1. Apply controller-level constraints (deck_id, owned_only) on top,
 *   2. Issue a direct SELECT against scryfall_oracles,
 *   3. Emit the user-visible warnings list.
 *
 * Colour operators (c:, ci:, commander:) emit bit-mask predicates against
 * colors_bits / color_identity_bits — indexable integer ops that replace
 * the non-sargable JSON_CONTAINS / JSON_OVERLAPS / JSON_LENGTH chain on
 * scryfall_cards.
 *
 * Grammar (informal):
 *   expr     := orGroup
 *   orGroup  := andGroup ("OR" andGroup)*
 *   andGroup := unary+            (implicit AND)
 *   unary    := ("-" | "NOT") unary | atom
 *   atom     := "(" expr ")" | op_clause | bareword
 *
 * Supported operators are listed in $this->supportedOperators; unknown
 * operators add a warning and drop the constraint rather than failing.
 */
class CardSearchService
{
    private const RARITIES = ['common', 'uncommon', 'rare', 'mythic'];

    private const COLORS = ['W', 'U', 'B', 'R', 'G'];

    /**
     * Format enum values pulled from decks.format. Unknown formats in `f:`
     * / `banned:` / `restricted:` emit a warning and drop the constraint.
     */
    public const FORMAT_WHITELIST = [
        'commander', 'brawler', 'standard', 'pioneer',
        'modern', 'legacy', 'vintage', 'pauper',
    ];

    /** Operators that feed into sort, not WHERE. */
    private const SORT_OPERATORS = ['order', 'direction'];

    /** `is:` values handled by clause emitters rather than column lookups. */
    private const IS_VALUES = [
        'commander', 'oathbreaker', 'partner', 'gc',
        'dfc', 'transform', 'mdfc', 'flip', 'meld', 'split', 'leveler',
        'reserved', 'brawler', 'companion', 'playtest',
    ];

    /**
     * Card types hidden from default catalog results. The user's query
     * can surface them by specifying `t:<hidden-type>` or by using an
     * exact-bang match (`!"Every Hope Shall Vanish"`). Covers supplemental-
     * format cards (Scheme / Plane / Phenomenon / Vanguard) that live in
     * real sets but aren't what people are browsing for by default.
     * Conspiracy & Dungeon are here for the same reason — Conspiracy
     * cards appear in draft_innovation sets alongside regular cards, and
     * Dungeons can appear in main expansion sets (AFR et al.).
     */
    public const DEFAULT_HIDDEN_TYPES = [
        'Scheme', 'Plane', 'Phenomenon', 'Vanguard', 'Conspiracy', 'Dungeon',
    ];

    /**
     * @param  array{disable_defaults?: bool}  $options
     * @return array{builder: Builder, warnings: array<int, string>, sort: array{column: string, direction: string}, defaults_applied: bool}
     *
     * `disable_defaults=true` skips the default-hidden-type and
     * default-playtest filters. Controller uses this as a second-pass
     * retry when the first pass returned zero results — if the user's
     * query happened to match *only* hidden/playtest cards, the retry
     * surfaces them.
     */
    public function search(string $query, array $options = []): array
    {
        $warnings = [];
        $sort = ['column' => 'name', 'direction' => 'asc'];

        $builder = ScryfallOracle::query();

        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['builder' => $builder, 'warnings' => $warnings, 'sort' => $sort, 'defaults_applied' => false];
        }

        $tokens = $this->tokenize($trimmed);
        $pos = 0;
        $ast = $this->parseOr($tokens, $pos);

        // Extract sort directives (`order:`, `direction:`) and remove them
        // from the tree so they don't emit WHERE clauses.
        $this->extractSort($ast, $sort, $warnings);

        if (! $this->isEmpty($ast)) {
            $this->applyNode($builder, $ast, $warnings);
        }

        // Default "hidden" categories the user didn't ask for. Skipped
        // entirely when the user bang-exact-matched a card name — if they
        // typed `!"Blessed Hippogriff"` they get the exact card they asked
        // for, regardless of type or set. Also skipped when the caller
        // explicitly asks (retry path when the first pass found nothing).
        $defaultsApplied = false;
        if (! ($options['disable_defaults'] ?? false)) {
            $analysis = $this->analyzeQuery($ast);
            if (! $analysis['hasExactMatch']) {
                $this->applyDefaultHiddenTypeFilter($builder, $analysis['requestedHiddenTypes']);
                if (! $analysis['isPlaytestRequested']) {
                    $this->applyDefaultPlaytestFilter($builder);
                }
                $defaultsApplied = true;
            }
        }

        return ['builder' => $builder, 'warnings' => $warnings, 'sort' => $sort, 'defaults_applied' => $defaultsApplied];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tokenizer
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Split the raw query into a flat list of tokens. Each token is one of:
     *   ['LPAREN'], ['RPAREN'], ['OR']
     *   ['ATOM', value, quoted:bool, exact:bool, negated:bool]
     *   ['NOT']                         -- standalone NOT token (group scope)
     *
     * Leaves `-` attached to the following atom (`-t:land` yields a negated
     * atom). A standalone `-(` opens a negated group — the `-` becomes a
     * NOT token so the parser applies it to the whole paren group.
     *
     * @return array<int, array<int|string, mixed>>
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($input);

        while ($i < $n) {
            $ch = $input[$i];

            // Whitespace — skip.
            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            // Parens.
            if ($ch === '(') {
                $tokens[] = ['LPAREN'];
                $i++;
                continue;
            }
            if ($ch === ')') {
                $tokens[] = ['RPAREN'];
                $i++;
                continue;
            }

            // Negation: "-" immediately before a group/atom. A hyphen
            // inside an atom is handled by the atom reader.
            if ($ch === '-' && $i + 1 < $n && ! ctype_space($input[$i + 1])) {
                if ($input[$i + 1] === '(') {
                    $tokens[] = ['NOT'];
                    $i++;
                    continue;
                }
                // Leaf negation: read the next atom and mark it negated.
                $i++;
                $atom = $this->readAtom($input, $i);
                if ($atom !== null) {
                    $atom['negated'] = true;
                    $tokens[] = $atom;
                }
                continue;
            }

            // Bare NOT / OR keywords — boundary-sensitive: only at start-of-
            // token, followed by a space or paren.
            if ($this->matchKeyword($input, $i, 'NOT')) {
                $tokens[] = ['NOT'];
                $i += 3;
                continue;
            }
            if ($this->matchKeyword($input, $i, 'OR')) {
                $tokens[] = ['OR'];
                $i += 2;
                continue;
            }

            // Anything else starts an atom.
            $atom = $this->readAtom($input, $i);
            if ($atom !== null) {
                $tokens[] = $atom;
            }
        }

        return $tokens;
    }

    /**
     * Consume one atom beginning at $input[$pos], advancing $pos past it.
     * An atom is:
     *   - `op:value` or `op<=value` etc.
     *   - `!"quoted"` (exact bang)
     *   - `"quoted"` (literal phrase)
     *   - a bareword
     * Value portion can itself be quoted (e.g. `t:"time lord"`).
     *
     * @return array<int|string, mixed>|null
     */
    private function readAtom(string $input, int &$pos): ?array
    {
        $n = strlen($input);
        $exact = false;

        // Standalone "!" prefix → exact name match.
        if ($input[$pos] === '!' && $pos + 1 < $n && $input[$pos + 1] === '"') {
            $exact = true;
            $pos++;
        }

        // Pure quoted string (no op prefix).
        if ($input[$pos] === '"') {
            $val = $this->readQuoted($input, $pos);
            return [
                'ATOM',
                'value'      => $val,
                'op'         => null,
                'comparator' => ':',
                'quoted'     => true,
                'exact'      => $exact,
                'negated'    => false,
            ];
        }

        // Read up to the first `:`, `=`, `<`, `>`, `!` (comparator) or
        // whitespace / paren. If we hit an operator char, switch to "read
        // value" mode including potential quoting.
        $start = $pos;
        $op = null;
        $comparator = ':';

        while ($pos < $n) {
            $c = $input[$pos];
            if (ctype_space($c) || $c === '(' || $c === ')') {
                break;
            }
            if ($c === ':' || $c === '=' || $c === '<' || $c === '>' || $c === '!') {
                // Record the op we've read so far — only if it's non-empty
                // and alpha-ish. Otherwise treat this as part of a bareword.
                $head = substr($input, $start, $pos - $start);
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $head)) {
                    $op = strtolower($head);

                    // Compound comparators: <=, >=, !=.
                    if (($c === '<' || $c === '>' || $c === '!') && $pos + 1 < $n && $input[$pos + 1] === '=') {
                        $comparator = $c . '=';
                        $pos += 2;
                    } else {
                        $comparator = $c;
                        $pos++;
                    }

                    // Read the value — quoted or bareword.
                    $val = null;
                    if ($pos < $n && $input[$pos] === '"') {
                        $val = $this->readQuoted($input, $pos);
                        return [
                            'ATOM',
                            'value'      => $val,
                            'op'         => $op,
                            'comparator' => $comparator,
                            'quoted'     => true,
                            'exact'      => false,
                            'negated'    => false,
                        ];
                    }

                    $valStart = $pos;
                    while ($pos < $n) {
                        $cc = $input[$pos];
                        if (ctype_space($cc) || $cc === '(' || $cc === ')') {
                            break;
                        }
                        $pos++;
                    }
                    $val = substr($input, $valStart, $pos - $valStart);
                    return [
                        'ATOM',
                        'value'      => $val,
                        'op'         => $op,
                        'comparator' => $comparator,
                        'quoted'     => false,
                        'exact'      => false,
                        'negated'    => false,
                    ];
                }
                // Not an op-like head — fall through and consume the char
                // as part of a bareword.
            }
            $pos++;
        }

        $word = substr($input, $start, $pos - $start);
        if ($word === '') {
            return null;
        }
        return [
            'ATOM',
            'value'      => $word,
            'op'         => null,
            'comparator' => ':',
            'quoted'     => false,
            'exact'      => false,
            'negated'    => false,
        ];
    }

    private function readQuoted(string $input, int &$pos): string
    {
        $n = strlen($input);
        // consume opening quote
        $pos++;
        $start = $pos;
        while ($pos < $n && $input[$pos] !== '"') {
            $pos++;
        }
        $val = substr($input, $start, $pos - $start);
        if ($pos < $n) {
            $pos++; // consume closing quote
        }
        return $val;
    }

    private function matchKeyword(string $input, int $pos, string $kw): bool
    {
        $len = strlen($kw);
        if (strcasecmp(substr($input, $pos, $len), $kw) !== 0) {
            return false;
        }
        // Must be word-bounded: preceded by whitespace/paren/start AND
        // followed by whitespace/paren/end.
        $before = $pos === 0 ? ' ' : $input[$pos - 1];
        $afterIdx = $pos + $len;
        $after = $afterIdx >= strlen($input) ? ' ' : $input[$afterIdx];
        $boundary = fn ($c) => ctype_space($c) || $c === '(' || $c === ')';
        return $boundary($before) && $boundary($after);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Parser
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Parse OR-separated AND groups. Returns a group node (or a single leaf
     * if there's only one child and it's a leaf — we always wrap in a group
     * anyway for uniform application).
     *
     * @param  array<int, array<int|string, mixed>>  $tokens
     * @return array<string, mixed>
     */
    private function parseOr(array $tokens, int &$pos): array
    {
        $children = [$this->parseAnd($tokens, $pos)];
        while ($pos < count($tokens) && $tokens[$pos][0] === 'OR') {
            $pos++;
            $children[] = $this->parseAnd($tokens, $pos);
        }
        if (count($children) === 1) {
            return $children[0];
        }
        return [
            'kind'     => 'group',
            'op'       => 'OR',
            'children' => $children,
            'negated'  => false,
        ];
    }

    /**
     * Parse a sequence of unary-prefixed atoms joined by implicit AND.
     *
     * @param  array<int, array<int|string, mixed>>  $tokens
     * @return array<string, mixed>
     */
    private function parseAnd(array $tokens, int &$pos): array
    {
        $children = [];
        while ($pos < count($tokens)) {
            $t = $tokens[$pos];
            $type = $t[0];
            if ($type === 'OR' || $type === 'RPAREN') {
                break;
            }
            $children[] = $this->parseUnary($tokens, $pos);
        }
        if (empty($children)) {
            // Empty AND group — matches everything. Representing it as a
            // group with no children keeps the applyNode recursion simple.
            return [
                'kind'     => 'group',
                'op'       => 'AND',
                'children' => [],
                'negated'  => false,
            ];
        }
        if (count($children) === 1) {
            return $children[0];
        }
        return [
            'kind'     => 'group',
            'op'       => 'AND',
            'children' => $children,
            'negated'  => false,
        ];
    }

    /**
     * Parse an optional NOT followed by an atom/group.
     *
     * @param  array<int, array<int|string, mixed>>  $tokens
     * @return array<string, mixed>
     */
    private function parseUnary(array $tokens, int &$pos): array
    {
        $negCount = 0;
        while ($pos < count($tokens) && $tokens[$pos][0] === 'NOT') {
            $negCount++;
            $pos++;
        }

        $node = $this->parsePrimary($tokens, $pos);
        if ($negCount % 2 === 1) {
            $node['negated'] = ! ($node['negated'] ?? false);
        }
        return $node;
    }

    /**
     * Parse a paren group or a leaf atom.
     *
     * @param  array<int, array<int|string, mixed>>  $tokens
     * @return array<string, mixed>
     */
    private function parsePrimary(array $tokens, int &$pos): array
    {
        $t = $tokens[$pos] ?? null;
        if ($t === null) {
            return ['kind' => 'leaf', 'atom' => null, 'negated' => false];
        }
        if ($t[0] === 'LPAREN') {
            $pos++;
            $node = $this->parseOr($tokens, $pos);
            if (isset($tokens[$pos]) && $tokens[$pos][0] === 'RPAREN') {
                $pos++;
            }
            return $node;
        }
        // ATOM
        $pos++;
        return [
            'kind'    => 'leaf',
            'atom'    => $t,
            'negated' => $t['negated'] ?? false,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // AST → warnings + sort extraction
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Walk the tree, pulling `order:` / `direction:` leaves out and into the
     * $sort array. Removes them from the tree so they don't emit WHERE.
     * Recorded warnings cover unsupported sort columns.
     *
     * @param  array<string, mixed>  $node
     * @param  array{column: string, direction: string}  $sort
     * @param  array<int, string>  $warnings
     */
    private function extractSort(array &$node, array &$sort, array &$warnings): void
    {
        if ($node['kind'] === 'group') {
            $kept = [];
            foreach ($node['children'] as &$child) {
                $this->extractSort($child, $sort, $warnings);
                if (! ($child['_strip'] ?? false)) {
                    $kept[] = $child;
                }
            }
            unset($child);
            $node['children'] = $kept;
            return;
        }

        $atom = $node['atom'] ?? null;
        if ($atom === null) {
            return;
        }
        $op = $atom['op'] ?? null;
        if ($op === null) {
            return;
        }

        if (! in_array($op, self::SORT_OPERATORS, true)) {
            return;
        }

        $value = strtolower((string) $atom['value']);
        if ($op === 'direction') {
            if (in_array($value, ['asc', 'desc'], true)) {
                $sort['direction'] = $value;
            } else {
                $warnings[] = "Invalid direction: '{$value}' (expected asc|desc)";
            }
        } else { // order
            $allowed = ['name', 'cmc', 'mv', 'rarity', 'power', 'toughness', 'set', 'released', 'edhrec'];
            $unsupported = ['usd', 'eur', 'tix', 'color'];
            if (in_array($value, $allowed, true)) {
                $sort['column'] = $value === 'mv' ? 'cmc' : $value;
            } elseif (in_array($value, $unsupported, true)) {
                $warnings[] = "Sort column '{$value}' is not supported";
            } else {
                $warnings[] = "Unknown sort column '{$value}'";
            }
        }

        // Clear the atom so isEmpty() treats this leaf as empty when it's
        // the root node (parser collapses single-child AND groups, so a
        // query of just `order:edhrec` arrives here as a bare leaf with no
        // surrounding group to filter it out via the _strip flag).
        $node['_strip'] = true;
        $node['atom'] = null;
    }

    /**
     * Walks the AST once to detect query-level intent that drives the
     * default-hidden filters. Returns:
     *   - hasExactMatch         — any bang-exact leaf (`!"..."`) anywhere.
     *                             Turns off ALL default-hidden filters so
     *                             the user's literal name query wins.
     *   - requestedHiddenTypes  — subset of DEFAULT_HIDDEN_TYPES that the
     *                             user asked for via `t:` / `type:`. These
     *                             types don't get hidden.
     *   - isPlaytestRequested   — true when the user typed `is:playtest`.
     *
     * @param  array<string, mixed>  $node
     * @return array{hasExactMatch: bool, requestedHiddenTypes: array<int, string>, isPlaytestRequested: bool}
     */
    private function analyzeQuery(array $node): array
    {
        $state = [
            'hasExactMatch'        => false,
            'requestedHiddenTypes' => [],
            'isPlaytestRequested'  => false,
        ];
        $this->walkForAnalysis($node, $state);
        $state['requestedHiddenTypes'] = array_values(array_unique($state['requestedHiddenTypes']));
        return $state;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     */
    private function walkForAnalysis(array $node, array &$state): void
    {
        if (($node['kind'] ?? null) === 'group') {
            foreach ($node['children'] as $child) {
                $this->walkForAnalysis($child, $state);
            }
            return;
        }
        $atom = $node['atom'] ?? null;
        if ($atom === null) return;

        if (! empty($atom['exact'])) {
            $state['hasExactMatch'] = true;
        }

        $op = $atom['op'] ?? null;
        if ($op === null) return;
        $op = $this->resolveAlias($op);
        $value = (string) ($atom['value'] ?? '');

        if ($op === 'type') {
            $titled = $this->titleCase($value);
            if (in_array($titled, self::DEFAULT_HIDDEN_TYPES, true)) {
                $state['requestedHiddenTypes'][] = $titled;
            }
        }
        if ($op === 'is' && in_array(strtolower($value), ['playtest', 'play-test'], true)) {
            $state['isPlaytestRequested'] = true;
        }
    }

    /**
     * Hide cards whose `types` JSON array overlaps any hidden type the
     * user didn't explicitly ask for. Skips the filter entirely if every
     * hidden type was requested, or if the list is already empty.
     *
     * @param  array<int, string>  $requestedHiddenTypes
     */
    private function applyDefaultHiddenTypeFilter(Builder $b, array $requestedHiddenTypes): void
    {
        $toHide = array_values(array_diff(self::DEFAULT_HIDDEN_TYPES, $requestedHiddenTypes));
        if (empty($toHide)) return;

        $b->where(function (Builder $q) use ($toHide) {
            $q->whereNull('types')
              ->orWhereRaw('NOT JSON_OVERLAPS(types, ?)', [json_encode($toHide)]);
        });
    }

    /**
     * Hide playtest cards unless is:playtest was requested. Uses the
     * `is_playtest_any` column on scryfall_oracles — true when any
     * printing of the oracle is marked playtest at sync time (Scryfall's
     * promo_types array, plus the CMB set-code fallback).
     */
    private function applyDefaultPlaytestFilter(Builder $b): void
    {
        $b->where('is_playtest_any', false);
    }

    /**
     * True when the node contributes no WHERE constraints (empty group,
     * or a group whose children are all empty). Lets the caller skip the
     * where-closure wrapper entirely.
     *
     * @param  array<string, mixed>  $node
     */
    private function isEmpty(array $node): bool
    {
        if ($node['kind'] === 'group') {
            foreach ($node['children'] as $c) {
                if (! $this->isEmpty($c)) {
                    return false;
                }
            }
            return true;
        }
        return ($node['atom'] ?? null) === null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // AST → SQL (WHERE application)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $warnings
     */
    private function applyNode(Builder $builder, array $node, array &$warnings): void
    {
        $negated = (bool) ($node['negated'] ?? false);

        $apply = function (Builder $inner) use ($node, &$warnings): void {
            if ($node['kind'] === 'group') {
                $op = $node['op'] ?? 'AND';
                foreach ($node['children'] as $child) {
                    if ($op === 'AND') {
                        $inner->where(function (Builder $sub) use ($child, &$warnings) {
                            $this->applyNode($sub, $child, $warnings);
                        });
                    } else { // OR
                        $inner->orWhere(function (Builder $sub) use ($child, &$warnings) {
                            $this->applyNode($sub, $child, $warnings);
                        });
                    }
                }
                return;
            }
            // leaf
            $atom = $node['atom'] ?? null;
            if ($atom === null) {
                return;
            }
            $this->applyAtom($inner, $atom, $warnings);
        };

        if ($negated) {
            $builder->whereNot(function (Builder $sub) use ($apply) {
                $apply($sub);
            });
        } else {
            $builder->where(function (Builder $sub) use ($apply) {
                $apply($sub);
            });
        }
    }

    /**
     * @param  array<int|string, mixed>  $atom
     * @param  array<int, string>  $warnings
     */
    private function applyAtom(Builder $b, array $atom, array &$warnings): void
    {
        $op = $atom['op'] ?? null;
        $comparator = $atom['comparator'] ?? ':';
        $value = (string) $atom['value'];
        $exact = (bool) ($atom['exact'] ?? false);
        $quoted = (bool) ($atom['quoted'] ?? false);

        // Bare text / quoted-no-op / exact → name.
        if ($op === null) {
            if ($exact) {
                $b->where('name', '=', $value);
                return;
            }
            $b->where('name', 'LIKE', '%'.$value.'%');
            return;
        }

        // Alias resolution.
        $op = $this->resolveAlias($op);

        switch ($op) {
            case 'name':
                if ($exact) {
                    $b->where('name', '=', $value);
                } else {
                    $b->where('name', 'LIKE', '%'.$value.'%');
                }
                return;

            case 'oracle':
                $b->where(function (Builder $q) use ($value) {
                    $q->where('oracle_text', 'LIKE', '%'.$value.'%')
                      ->orWhere('oracle_text_back', 'LIKE', '%'.$value.'%');
                });
                return;

            case 'fulloracle':
                $b->where(function (Builder $q) use ($value) {
                    $q->where('printed_text', 'LIKE', '%'.$value.'%')
                      ->orWhere('printed_text_back', 'LIKE', '%'.$value.'%')
                      ->orWhere('oracle_text', 'LIKE', '%'.$value.'%')
                      ->orWhere('oracle_text_back', 'LIKE', '%'.$value.'%');
                });
                return;

            case 'type':
                $this->applyTypeClause($b, $value, $quoted);
                return;

            case 'color':
                $this->applyColorClause($b, 'colors_bits', $value, $comparator);
                return;

            case 'identity':
                $this->applyColorClause($b, 'color_identity_bits', $value, $comparator);
                return;

            case 'commander':
                // Subset-only — no comparators allowed. Matches any oracle
                // whose color identity is a subset of the commander's
                // identity. Bit-mask form: `(bits & ~target) = 0`, which
                // also cleanly covers colourless (bits = 0).
                if ($comparator !== ':' && $comparator !== '=') {
                    $warnings[] = "commander: does not accept comparator '{$comparator}'";
                    return;
                }
                $mask = BulkSyncService::buildColorBits($this->parseColorLetters($value));
                $b->whereRaw('(color_identity_bits & ?) = 0', [~$mask & 0b11111]);
                return;

            case 'mana':
                // Normalise pip ordering by stripping braces and sorting
                // WUBRG letters; then LIKE on the stored mana_cost.
                $b->where('mana_cost', 'LIKE', '%'.$value.'%');
                return;

            case 'cmc':
                $this->applyNumericComparator($b, 'cmc', $value, $comparator, fn ($v) => (float) $v, $warnings);
                return;

            case 'power':
                $this->applyStarNumericComparator($b, 'power', $value, $comparator, $warnings);
                return;

            case 'toughness':
                $this->applyStarNumericComparator($b, 'toughness', $value, $comparator, $warnings);
                return;

            case 'loyalty':
                $this->applyStarNumericComparator($b, 'loyalty', $value, $comparator, $warnings);
                return;

            case 'rarity':
                $this->applyRarityClause($b, $value, $comparator, $warnings);
                return;

            case 'set':
                // "Oracle has any printing in this set." EXISTS correlated
                // on oracle_id — preserves the per-printing semantic the
                // window-wrapped query had, without reintroducing a JOIN.
                $set = strtolower($value);
                $b->whereExists(function ($q) use ($set) {
                    $q->select('*')
                      ->from('scryfall_cards as sc')
                      ->whereColumn('sc.oracle_id', 'scryfall_oracles.oracle_id')
                      ->where('sc.set_code', '=', $set);
                });
                return;

            case 'number':
                $b->whereExists(function ($q) use ($value) {
                    $q->select('*')
                      ->from('scryfall_cards as sc')
                      ->whereColumn('sc.oracle_id', 'scryfall_oracles.oracle_id')
                      ->where('sc.collector_number', '=', $value);
                });
                return;

            case 'format':
                $this->applyLegalityClause($b, $value, 'legal', $warnings);
                return;

            case 'banned':
                $this->applyLegalityClause($b, $value, 'banned', $warnings);
                return;

            case 'restricted':
                $this->applyLegalityClause($b, $value, 'restricted', $warnings);
                return;

            case 'keyword':
                $b->whereRaw(
                    'JSON_OVERLAPS(keywords, ?)',
                    [json_encode([$this->titleCase($value)])],
                );
                return;

            case 'otag':
                // EXISTS subquery against card_oracle_tags, correlated on
                // the outer scryfall_oracles.oracle_id.
                $b->whereExists(function ($q) use ($value) {
                    $q->select('*')
                      ->from('card_oracle_tags as cot')
                      ->whereColumn('cot.oracle_id', 'scryfall_oracles.oracle_id')
                      ->where('cot.tag', '=', $value);
                });
                return;

            case 'is':
                $this->applyIsClause($b, strtolower($value), $warnings);
                return;

            default:
                $warnings[] = "Operator '{$op}' is not supported";
                return;
        }
    }

    /**
     * Canonical alias map. Returns the base op for any known alias, or the
     * input unchanged for unknown ops (which will be rejected downstream).
     */
    private function resolveAlias(string $op): string
    {
        return match ($op) {
            'n'                                              => 'name',
            'o'                                              => 'oracle',
            'fo'                                             => 'fulloracle',
            't'                                              => 'type',
            'c'                                              => 'color',
            'ci', 'id', 'color_identity'                     => 'identity',
            'm'                                              => 'mana',
            'mv', 'manavalue'                                => 'cmc',
            'pow'                                            => 'power',
            'tou'                                            => 'toughness',
            'loy'                                            => 'loyalty',
            'r'                                              => 'rarity',
            's', 'e', 'edition'                              => 'set',
            'cn'                                             => 'number',
            'f', 'legal'                                     => 'format',
            'kw'                                             => 'keyword',
            'function', 'oracletag'                          => 'otag',
            default                                          => $op,
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Clause helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * `t:` searches supertypes, types, AND subtypes (any match wins). For
     * quoted multi-word values, fall back to a direct LIKE on type_line
     * so "artifact creature" matches the compound type-line.
     */
    private function applyTypeClause(Builder $b, string $value, bool $quoted): void
    {
        $titled = $this->titleCase($value);

        if ($quoted && str_contains($value, ' ')) {
            // Multi-word quoted: probe JSON arrays for an exact multi-word
            // subtype match (e.g. "Time Lord") AND also allow a raw LIKE
            // fallback on type_line for compound phrases ("artifact creature").
            $b->where(function (Builder $q) use ($titled, $value) {
                $q->whereRaw('JSON_OVERLAPS(subtypes, ?)', [json_encode([$titled])])
                  ->orWhereRaw('JSON_OVERLAPS(supertypes, ?)', [json_encode([$titled])])
                  ->orWhereRaw('JSON_OVERLAPS(types, ?)', [json_encode([$titled])])
                  ->orWhere('type_line', 'LIKE', '%'.$value.'%');
            });
            return;
        }

        $b->where(function (Builder $q) use ($titled, $value) {
            $q->whereRaw('JSON_OVERLAPS(supertypes, ?)', [json_encode([$titled])])
              ->orWhereRaw('JSON_OVERLAPS(types, ?)', [json_encode([$titled])])
              ->orWhereRaw('JSON_OVERLAPS(subtypes, ?)', [json_encode([$titled])])
              ->orWhere('type_line', 'LIKE', '%'.$value.'%');
        });
    }

    /**
     * `c:` / `ci:` with all comparators. `c:c` / `c:m` shortcuts handled
     * before the letter parsing branch. Operates on the TINYINT bit-mask
     * columns (colors_bits / color_identity_bits) — W=1, U=2, B=4, R=8,
     * G=16. Comparators:
     *
     *   c:wu / c<=wu  subset of WU     — (bits & ~target) = 0
     *   c=wu          exactly WU       — bits = target
     *   c<wu          strict subset    — (bits & ~target) = 0 AND bits != target
     *   c>=wu         superset         — (bits & target) = target
     *   c>wu          strict superset  — (bits & target) = target AND bits != target
     *   c!=wu         not exactly WU   — bits != target
     *
     * 0b11111 (31) is the full WUBRG mask; we AND ~target with it to
     * normalise the complement to a 5-bit space.
     */
    private function applyColorClause(Builder $b, string $column, string $value, string $comparator): void
    {
        $lower = strtolower($value);
        if ($lower === 'c' || $lower === 'colorless') {
            $b->where($column, '=', 0);
            return;
        }
        if ($lower === 'm' || $lower === 'multicolor') {
            // Two-or-more bits set — no clean bitwise form, use bit-count.
            $b->whereRaw("BIT_COUNT({$column}) >= 2");
            return;
        }

        $target = BulkSyncService::buildColorBits($this->parseColorLetters($value));
        $comp = $comparator === ':' ? '>=' : $comparator;
        $complement = ~$target & 0b11111;

        switch ($comp) {
            case '>=':
                $b->whereRaw("({$column} & ?) = ?", [$target, $target]);
                return;

            case '=':
                $b->where($column, '=', $target);
                return;

            case '<=':
                $b->whereRaw("({$column} & ?) = 0", [$complement]);
                return;

            case '<':
                $b->whereRaw("({$column} & ?) = 0", [$complement]);
                $b->where($column, '!=', $target);
                return;

            case '>':
                $b->whereRaw("({$column} & ?) = ?", [$target, $target]);
                $b->where($column, '!=', $target);
                return;

            case '!=':
                $b->where($column, '!=', $target);
                return;
        }
    }

    private function parseColorLetters(string $value): array
    {
        $upper = strtoupper($value);
        $letters = array_values(array_filter(
            array_unique(str_split($upper)),
            fn ($c) => in_array($c, self::COLORS, true),
        ));
        $order = array_flip(self::COLORS);
        usort($letters, fn ($a, $b) => $order[$a] <=> $order[$b]);
        return $letters;
    }

    /**
     * Numeric comparator, nulls-last behaviour is applied at sort time — for
     * WHERE we just skip rows whose column is NULL on strict comparisons.
     *
     * @param  callable  $cast  cast function from string → numeric
     * @param  array<int, string>  $warnings
     */
    private function applyNumericComparator(Builder $b, string $column, string $value, string $comparator, callable $cast, array &$warnings): void
    {
        if (! is_numeric($value)) {
            $warnings[] = "Non-numeric value for {$column}: '{$value}'";
            return;
        }
        $v = $cast($value);
        $sqlOp = $comparator === ':' ? '=' : $comparator;
        $b->where($column, $sqlOp, $v);
    }

    /**
     * Power / toughness / loyalty use the same grammar. `*` and `X` become 0.
     *
     * @param  array<int, string>  $warnings
     */
    private function applyStarNumericComparator(Builder $b, string $column, string $value, string $comparator, array &$warnings): void
    {
        $v = strtoupper(trim($value));
        $cast = match (true) {
            $v === '*' || $v === 'X' => 0,
            is_numeric($v)           => (int) $v,
            default                  => null,
        };
        if ($cast === null) {
            $warnings[] = "Non-numeric value for {$column}: '{$value}'";
            return;
        }
        $sqlOp = $comparator === ':' ? '=' : $comparator;
        $b->whereRaw(
            "(CASE WHEN {$column} IN ('*','X') THEN 0 ELSE CAST({$column} AS SIGNED) END) {$sqlOp} ?",
            [$cast],
        );
        // Never match nulls on strict comparators.
        $b->whereNotNull($column);
    }

    /**
     * `r:` with comparator uses FIELD() so `r>common` sorts by rarity rank.
     * EXISTS correlated on scryfall_cards.oracle_id so "oracle has any
     * printing at/above this rarity" matches the old per-printing semantic.
     *
     * @param  array<int, string>  $warnings
     */
    private function applyRarityClause(Builder $b, string $value, string $comparator, array &$warnings): void
    {
        $lower = strtolower($value);
        if (! in_array($lower, self::RARITIES, true)) {
            $warnings[] = "Unknown rarity: '{$value}'";
            return;
        }

        if ($comparator === ':' || $comparator === '=') {
            $b->whereExists(function ($q) use ($lower) {
                $q->select('*')
                  ->from('scryfall_cards as sc')
                  ->whereColumn('sc.oracle_id', 'scryfall_oracles.oracle_id')
                  ->where('sc.rarity', '=', $lower);
            });
            return;
        }

        $sqlOp = $comparator;
        $rankList = "'" . implode("','", self::RARITIES) . "'";
        $target = array_search($lower, self::RARITIES, true) + 1;
        $b->whereExists(function ($q) use ($rankList, $sqlOp, $target) {
            $q->select('*')
              ->from('scryfall_cards as sc')
              ->whereColumn('sc.oracle_id', 'scryfall_oracles.oracle_id')
              ->whereRaw("FIELD(sc.rarity, {$rankList}) {$sqlOp} ?", [$target]);
        });
    }

    /**
     * `f:` / `banned:` / `restricted:` — validated against FORMAT_WHITELIST.
     * Format name is interpolated into the JSON path because prepared
     * statements can't parameterise JSON path segments; the whitelist
     * check above makes interpolation safe.
     *
     * @param  array<int, string>  $warnings
     */
    private function applyLegalityClause(Builder $b, string $format, string $status, array &$warnings): void
    {
        $format = strtolower($format);
        if (! in_array($format, self::FORMAT_WHITELIST, true)) {
            $warnings[] = "Unknown format: '{$format}'";
            return;
        }
        $b->whereRaw(
            "JSON_EXTRACT(legalities, '$.\"" . $format . "\"') = ?",
            [$status],
        );
    }

    /**
     * `is:<x>` — each value has its own clause.
     *
     * @param  array<int, string>  $warnings
     */
    private function applyIsClause(Builder $b, string $value, array &$warnings): void
    {
        // `is:play-test` is accepted as a hyphen-alias of `is:playtest`.
        if ($value === 'play-test') $value = 'playtest';

        if (! in_array($value, self::IS_VALUES, true)) {
            $warnings[] = "is:{$value} is not supported";
            return;
        }

        switch ($value) {
            case 'commander':
                // Legendary Creature OR explicit "can be your commander"
                // OR any partner variant.
                $b->where(function (Builder $q) {
                    $q->where(function (Builder $inner) {
                        $inner->whereRaw('JSON_OVERLAPS(types, ?)', [json_encode(['Creature'])])
                              ->whereRaw('JSON_OVERLAPS(supertypes, ?)', [json_encode(['Legendary'])]);
                    })
                    ->orWhere(function (Builder $inner) {
                        $inner->where('oracle_text', 'LIKE', '%can be your commander%')
                              ->orWhere('oracle_text_back', 'LIKE', '%can be your commander%');
                    })
                    ->orWhereNotNull('partner_scope');
                });
                return;

            case 'oathbreaker':
                $b->whereRaw('JSON_OVERLAPS(types, ?)', [json_encode(['Planeswalker'])])
                  ->whereRaw('JSON_OVERLAPS(supertypes, ?)', [json_encode(['Legendary'])]);
                return;

            case 'partner':
                $b->whereNotNull('partner_scope');
                return;

            case 'gc':
                $b->where('commander_game_changer', true);
                return;

            case 'dfc':
                $b->where('is_dfc', true);
                return;

            case 'transform':
                $b->where('is_transform', true);
                return;

            case 'mdfc':
                $b->where('is_mdfc', true);
                return;

            case 'flip':
                $b->where('is_flip', true);
                return;

            case 'meld':
                $b->where('is_meld', true);
                return;

            case 'split':
                $b->where('is_split', true);
                return;

            case 'leveler':
                $b->where('is_leveler', true);
                return;

            case 'reserved':
                $b->where('reserved', true);
                return;

            case 'brawler':
                $b->where(function (Builder $q) {
                    $q->whereRaw('JSON_OVERLAPS(types, ?)', [json_encode(['Creature'])])
                      ->orWhereRaw('JSON_OVERLAPS(types, ?)', [json_encode(['Planeswalker'])]);
                })->whereRaw('JSON_OVERLAPS(supertypes, ?)', [json_encode(['Legendary'])]);
                return;

            case 'companion':
                $b->whereRaw('JSON_OVERLAPS(keywords, ?)', [json_encode(['Companion'])]);
                return;

            case 'playtest':
                // is_playtest_any rolled up to oracle grain at sync time
                // (Scryfall's promo_types array plus CMB set-code fallback).
                $b->where('is_playtest_any', true);
                return;
        }
    }

    /**
     * Title-case a value for matching the JSON array elements that Scryfall
     * stores Title Case (e.g. "elf warrior" → "Elf Warrior"). MySQL JSON
     * comparisons are case-sensitive regardless of column collation.
     */
    private function titleCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sort
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Sort-key SQL for card name, with the leading `A-` prefix stripped.
     * Alchemy-rebalanced cards (e.g. `A-Blessed Hippogriff`) would otherwise
     * clump at the top of name-ordered results; stripping the prefix at
     * sort time puts them adjacent to their non-rebalanced counterparts.
     * Display name is unchanged — this only affects ORDER BY.
     */
    private function sortableName(): string
    {
        return "IF(name LIKE 'A-%', SUBSTRING(name, 3), name)";
    }

    /**
     * Emit the ORDER BY fragment for the outer search query. Called by
     * the controller after the builder is materialised. Returns a raw SQL
     * fragment (no leading `ORDER BY`). Column references are all on
     * scryfall_oracles (rarity / set_code map to the default printing's
     * values stored as default_rarity / default_set_code).
     *
     * @param  array{column: string, direction: string}  $sort
     */
    public function buildOrderBy(array $sort): string
    {
        $dir = strtoupper($sort['direction']) === 'DESC' ? 'DESC' : 'ASC';
        $col = $sort['column'];
        $nameAsc = $this->sortableName() . ' ASC';

        switch ($col) {
            case 'cmc':
                return "cmc {$dir}, {$nameAsc}";

            case 'rarity':
                $rankList = "'" . implode("','", self::RARITIES) . "'";
                return "FIELD(default_rarity, {$rankList}) {$dir}, {$nameAsc}";

            case 'power':
                return "power IS NULL ASC, CAST(CASE WHEN power IN ('*','X') THEN '0' ELSE power END AS SIGNED) {$dir}, {$nameAsc}";

            case 'toughness':
                return "toughness IS NULL ASC, CAST(CASE WHEN toughness IN ('*','X') THEN '0' ELSE toughness END AS SIGNED) {$dir}, {$nameAsc}";

            case 'set':
                return "default_set_code {$dir}, {$nameAsc}";

            case 'released':
                return "max_released_at {$dir}, {$nameAsc}";

            case 'edhrec':
                return "edhrec_rank IS NULL ASC, edhrec_rank {$dir}, {$nameAsc}";

            case 'name':
            default:
                return $this->sortableName() . " {$dir}";
        }
    }
}
