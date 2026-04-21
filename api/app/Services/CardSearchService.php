<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use App\Models\ScryfallCard;

/**
 * Parses Scryfall-style search syntax into an Eloquent builder against
 * scryfall_cards. Returns {builder, warnings, sort} so the controller can:
 *   1. Apply controller-level constraints (deck_id, owned_only) on top,
 *   2. Wrap the builder in the oracle-grouping window query,
 *   3. Emit the user-visible warnings list.
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
        'reserved', 'brawler', 'companion',
    ];

    /**
     * @return array{builder: Builder, warnings: array<int, string>, sort: array{column: string, direction: string}}
     */
    public function search(string $query, array $options = []): array
    {
        $warnings = [];
        $sort = ['column' => 'name', 'direction' => 'asc'];

        $builder = ScryfallCard::query();

        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['builder' => $builder, 'warnings' => $warnings, 'sort' => $sort];
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

        return ['builder' => $builder, 'warnings' => $warnings, 'sort' => $sort];
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

        $node['_strip'] = true;
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
                $this->applyColorClause($b, 'colors', $value, $comparator);
                return;

            case 'identity':
                $this->applyColorClause($b, 'color_identity', $value, $comparator);
                return;

            case 'commander':
                // Subset-only — no comparators allowed.
                if ($comparator !== ':' && $comparator !== '=') {
                    $warnings[] = "commander: does not accept comparator '{$comparator}'";
                    return;
                }
                $letters = $this->parseColorLetters($value);
                $b->whereRaw(
                    '(JSON_LENGTH(color_identity) = 0 OR JSON_CONTAINS(?, color_identity))',
                    [json_encode($letters)],
                );
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
                $b->where('set_code', '=', strtolower($value));
                return;

            case 'number':
                $b->where('collector_number', '=', $value);
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
                // EXISTS subquery against card_oracle_tags. Using whereRaw
                // so the EXISTS can reference scryfall_cards.oracle_id —
                // the outer table's column — from within a correlated join.
                $b->whereExists(function ($q) use ($value) {
                    $q->select('*')
                      ->from('card_oracle_tags as cot')
                      ->whereColumn('cot.oracle_id', 'scryfall_cards.oracle_id')
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
     * before the letter parsing branch.
     */
    private function applyColorClause(Builder $b, string $column, string $value, string $comparator): void
    {
        $lower = strtolower($value);
        if ($lower === 'c' || $lower === 'colorless') {
            $b->whereRaw("JSON_LENGTH({$column}) = 0");
            return;
        }
        if ($lower === 'm' || $lower === 'multicolor') {
            $b->whereRaw("JSON_LENGTH({$column}) >= 2");
            return;
        }

        $letters = $this->parseColorLetters($value);
        $target = json_encode($letters);

        switch ($comparator) {
            case ':':
            case '>=':
                // Superset: card's color set ⊇ target letters.
                foreach ($letters as $l) {
                    $b->whereRaw("JSON_CONTAINS({$column}, ?)", [json_encode($l)]);
                }
                return;

            case '=':
                $b->whereRaw("JSON_LENGTH({$column}) = ?", [count($letters)]);
                foreach ($letters as $l) {
                    $b->whereRaw("JSON_CONTAINS({$column}, ?)", [json_encode($l)]);
                }
                return;

            case '<=':
                // Subset: card's set ⊆ target (no letter outside target).
                $disallowed = array_values(array_diff(self::COLORS, $letters));
                foreach ($disallowed as $l) {
                    $b->whereRaw("NOT JSON_CONTAINS({$column}, ?)", [json_encode($l)]);
                }
                return;

            case '<':
                $disallowed = array_values(array_diff(self::COLORS, $letters));
                foreach ($disallowed as $l) {
                    $b->whereRaw("NOT JSON_CONTAINS({$column}, ?)", [json_encode($l)]);
                }
                $b->whereRaw("JSON_LENGTH({$column}) < ?", [count($letters)]);
                return;

            case '>':
                foreach ($letters as $l) {
                    $b->whereRaw("JSON_CONTAINS({$column}, ?)", [json_encode($l)]);
                }
                $b->whereRaw("JSON_LENGTH({$column}) > ?", [count($letters)]);
                return;

            case '!=':
                $b->where(function (Builder $q) use ($column, $letters) {
                    $q->whereRaw("JSON_LENGTH({$column}) != ?", [count($letters)]);
                    foreach ($letters as $l) {
                        $q->orWhereRaw("NOT JSON_CONTAINS({$column}, ?)", [json_encode($l)]);
                    }
                });
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
            $b->where('rarity', '=', $lower);
            return;
        }
        $sqlOp = $comparator;
        $rankList = "'" . implode("','", self::RARITIES) . "'";
        $target = array_search($lower, self::RARITIES, true) + 1;
        $b->whereRaw("FIELD(rarity, {$rankList}) {$sqlOp} ?", [$target]);
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
                $b->where('layout', 'transform');
                return;

            case 'mdfc':
                $b->where('layout', 'modal_dfc');
                return;

            case 'flip':
                $b->where('layout', 'flip');
                return;

            case 'meld':
                $b->where('layout', 'meld');
                return;

            case 'split':
                $b->where('layout', 'split');
                return;

            case 'leveler':
                $b->where('layout', 'leveler');
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
     * Emit the ORDER BY fragment for the outer window-wrapped query. Called
     * by the controller after it wraps the inner WHERE. Returns a raw SQL
     * fragment (no leading `ORDER BY`).
     *
     * @param  array{column: string, direction: string}  $sort
     */
    public function buildOrderBy(array $sort): string
    {
        $dir = strtoupper($sort['direction']) === 'DESC' ? 'DESC' : 'ASC';
        $col = $sort['column'];

        switch ($col) {
            case 'cmc':
                return "cmc {$dir}, name ASC";

            case 'rarity':
                $rankList = "'" . implode("','", self::RARITIES) . "'";
                return "FIELD(rarity, {$rankList}) {$dir}, name ASC";

            case 'power':
                return "power IS NULL ASC, CAST(CASE WHEN power IN ('*','X') THEN '0' ELSE power END AS SIGNED) {$dir}, name ASC";

            case 'toughness':
                return "toughness IS NULL ASC, CAST(CASE WHEN toughness IN ('*','X') THEN '0' ELSE toughness END AS SIGNED) {$dir}, name ASC";

            case 'set':
                return "set_code {$dir}, name ASC";

            case 'released':
                return "oracle_max_released {$dir}, name ASC";

            case 'edhrec':
                return "edhrec_rank IS NULL ASC, edhrec_rank {$dir}, name ASC";

            case 'name':
            default:
                return "name {$dir}";
        }
    }
}
