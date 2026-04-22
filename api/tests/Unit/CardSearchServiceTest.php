<?php

namespace Tests\Unit;

use App\Services\CardSearchService;
use Tests\TestCase;

/**
 * Parser-focused tests. Runs against a real DB via RefreshDatabase so we can
 * assert on the builder's rendered SQL + bindings — the parser produces an
 * Eloquent builder, and the rendered SQL is the only observable surface the
 * caller actually consumes.
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because toSql()
 * goes through the MySQL grammar which needs a bound DB connection.
 */
class CardSearchServiceTest extends TestCase
{
    private function svc(): CardSearchService
    {
        return new CardSearchService();
    }

    public function test_empty_query_produces_no_where(): void
    {
        $out = $this->svc()->search('');
        $this->assertSame([], $out['warnings']);
        $sql = $out['builder']->toSql();
        $this->assertStringNotContainsString('where', strtolower($sql));
    }

    public function test_bare_text_becomes_name_like(): void
    {
        $out = $this->svc()->search('lightning');
        $sql = $out['builder']->toSql();
        $bindings = $out['builder']->getBindings();
        $this->assertStringContainsString('`name` like', strtolower($sql));
        $this->assertContains('%lightning%', $bindings);
    }

    public function test_exact_bang_name(): void
    {
        $out = $this->svc()->search('!"Lightning Bolt"');
        $bindings = $out['builder']->getBindings();
        $this->assertContains('Lightning Bolt', $bindings);
        $sql = $out['builder']->toSql();
        $this->assertStringContainsString('`name` =', strtolower($sql));
    }

    public function test_quoted_literal_with_spaces(): void
    {
        $out = $this->svc()->search('"Lightning Bolt"');
        $bindings = $out['builder']->getBindings();
        $this->assertContains('%Lightning Bolt%', $bindings);
    }

    public function test_type_operator_checks_three_json_columns(): void
    {
        $out = $this->svc()->search('t:creature');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('supertypes', $sql);
        $this->assertStringContainsString('types', $sql);
        $this->assertStringContainsString('subtypes', $sql);
        $bindings = $out['builder']->getBindings();
        // Title-cased JSON array binding for Creature.
        $this->assertContains('["Creature"]', $bindings);
    }

    public function test_type_multiword_quoted(): void
    {
        $out = $this->svc()->search('t:"time lord"');
        $bindings = $out['builder']->getBindings();
        $this->assertContains('["Time Lord"]', $bindings);
    }

    public function test_color_superset_default(): void
    {
        $out = $this->svc()->search('c:wg');
        $sql = strtolower($out['builder']->toSql());
        $bindings = $out['builder']->getBindings();
        $this->assertStringContainsString('json_contains(colors', $sql);
        $this->assertContains('"W"', $bindings);
        $this->assertContains('"G"', $bindings);
    }

    public function test_color_equals_bounds_length(): void
    {
        $out = $this->svc()->search('c=wg');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('json_length(colors) =', $sql);
    }

    public function test_color_subset_uses_not_contains_for_disallowed(): void
    {
        $out = $this->svc()->search('c<=wg');
        $sql = strtolower($out['builder']->toSql());
        // W and G are allowed; U, B, R are disallowed.
        $this->assertStringContainsString('not json_contains(colors', $sql);
        $bindings = $out['builder']->getBindings();
        $this->assertContains('"U"', $bindings);
        $this->assertContains('"B"', $bindings);
        $this->assertContains('"R"', $bindings);
    }

    public function test_colorless_shortcut(): void
    {
        $out = $this->svc()->search('c:c');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('json_length(colors) = 0', $sql);
    }

    public function test_multicolor_shortcut(): void
    {
        $out = $this->svc()->search('c:m');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('json_length(colors) >= 2', $sql);
    }

    public function test_identity_alias_maps_to_color_identity(): void
    {
        $out = $this->svc()->search('id:wug');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('json_contains(color_identity', $sql);
    }

    public function test_commander_subset_only_no_comparators(): void
    {
        $out = $this->svc()->search('commander>=wu');
        // commander: rejects comparators other than : / =
        $this->assertNotEmpty($out['warnings']);
    }

    public function test_commander_subset_passes_colorless(): void
    {
        $out = $this->svc()->search('commander:wu');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('json_length(color_identity) = 0', $sql);
    }

    public function test_cmc_comparator(): void
    {
        $out = $this->svc()->search('cmc>=4');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('`cmc` >=', $sql);
    }

    public function test_power_star_becomes_zero(): void
    {
        $out = $this->svc()->search('pow>=*');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString("case when power in ('*','x')", $sql);
    }

    public function test_rarity_comparator_uses_field(): void
    {
        $out = $this->svc()->search('r>common');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString("field(rarity, 'common','uncommon','rare','mythic')", $sql);
    }

    public function test_format_legal(): void
    {
        $out = $this->svc()->search('f:commander');
        $sql = $out['builder']->toSql();
        $this->assertStringContainsString("JSON_EXTRACT(legalities, '$.\"commander\"')", $sql);
    }

    public function test_unknown_format_warns_and_drops(): void
    {
        $out = $this->svc()->search('f:foobar');
        $this->assertNotEmpty($out['warnings']);
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringNotContainsString('legalities', $sql);
    }

    public function test_is_commander_composite(): void
    {
        $out = $this->svc()->search('is:commander');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('partner_scope', $sql);
        $this->assertContains('%can be your commander%', $out['builder']->getBindings());
    }

    public function test_is_gc_shortcut(): void
    {
        $out = $this->svc()->search('is:gc');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('`commander_game_changer` = ', $sql);
    }

    public function test_otag_exists_subquery(): void
    {
        $out = $this->svc()->search('otag:ramp');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('exists', $sql);
        $this->assertStringContainsString('card_oracle_tags', $sql);
    }

    public function test_oracle_searches_both_faces(): void
    {
        $out = $this->svc()->search('o:graveyard');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('oracle_text', $sql);
        $this->assertStringContainsString('oracle_text_back', $sql);
    }

    public function test_fulloracle_includes_printed_text(): void
    {
        $out = $this->svc()->search('fo:flying');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('printed_text', $sql);
        $this->assertStringContainsString('printed_text_back', $sql);
    }

    public function test_or_grouping(): void
    {
        $out = $this->svc()->search('(r:rare OR r:mythic) t:creature');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString(' or ', $sql);
        // And t:creature must still be present.
        $this->assertStringContainsString('subtypes', $sql);
    }

    public function test_leaf_negation(): void
    {
        $out = $this->svc()->search('-t:equipment');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('not ', $sql);
    }

    public function test_not_keyword_negation(): void
    {
        $out = $this->svc()->search('NOT t:land');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('not ', $sql);
    }

    public function test_unsupported_op_warns(): void
    {
        $out = $this->svc()->search('art:terese');
        $this->assertNotEmpty($out['warnings']);
    }

    public function test_default_sort_is_name_asc(): void
    {
        $out = $this->svc()->search('t:creature');
        $this->assertSame('name', $out['sort']['column']);
        $this->assertSame('asc', $out['sort']['direction']);
    }

    public function test_order_rarity_desc(): void
    {
        $out = $this->svc()->search('t:creature order:rarity direction:desc');
        $this->assertSame('rarity', $out['sort']['column']);
        $this->assertSame('desc', $out['sort']['direction']);
    }

    public function test_order_extracted_from_where(): void
    {
        $out = $this->svc()->search('order:cmc');
        // order: should NOT have emitted a WHERE clause for cmc
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringNotContainsString('`cmc`', $sql);
    }

    public function test_order_price_unsupported_warns(): void
    {
        $out = $this->svc()->search('order:usd');
        $this->assertNotEmpty($out['warnings']);
    }

    public function test_build_order_by_rarity(): void
    {
        $svc = $this->svc();
        $out = $svc->buildOrderBy(['column' => 'rarity', 'direction' => 'desc']);
        $this->assertStringContainsString('FIELD(rarity', $out);
        $this->assertStringContainsString('DESC', $out);
    }

    public function test_build_order_by_released_uses_oracle_max(): void
    {
        $svc = $this->svc();
        $out = $svc->buildOrderBy(['column' => 'released', 'direction' => 'asc']);
        $this->assertStringContainsString('oracle_max_released', $out);
    }

    public function test_set_operator(): void
    {
        $out = $this->svc()->search('s:tdm');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('`set_code` =', $sql);
    }

    public function test_banned_operator(): void
    {
        $out = $this->svc()->search('banned:modern');
        $bindings = $out['builder']->getBindings();
        $this->assertContains('banned', $bindings);
    }

    public function test_keyword_operator_uses_json_overlaps(): void
    {
        $out = $this->svc()->search('kw:flying');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('json_overlaps(keywords', $sql);
        $this->assertContains('["Flying"]', $out['builder']->getBindings());
    }

    public function test_a_prefix_sort_strips_leading_a_dash(): void
    {
        $svc = $this->svc();
        $out = $svc->buildOrderBy(['column' => 'name', 'direction' => 'asc']);
        // A-rebalanced cards should sort by the non-prefixed name so they
        // don't clump at the top.
        $this->assertStringContainsString("IF(name LIKE 'A-%', SUBSTRING(name, 3), name)", $out);
    }

    public function test_build_order_by_cmc_uses_sortable_name_tiebreak(): void
    {
        $out = $this->svc()->buildOrderBy(['column' => 'cmc', 'direction' => 'asc']);
        $this->assertStringContainsString('SUBSTRING(name, 3)', $out);
    }

    public function test_default_hidden_types_excluded_by_default(): void
    {
        $out = $this->svc()->search('bare text');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('not json_overlaps(types', $sql);
        // Verify the full hidden-type list was sent.
        $this->assertContains(
            json_encode(['Scheme', 'Plane', 'Phenomenon', 'Vanguard', 'Conspiracy', 'Dungeon']),
            $out['builder']->getBindings(),
        );
    }

    public function test_t_scheme_drops_scheme_from_hidden(): void
    {
        $out = $this->svc()->search('t:scheme');
        $bindings = $out['builder']->getBindings();
        // The NOT JSON_OVERLAPS payload should no longer include 'Scheme'.
        // Other hidden types stay.
        $hiddenPayload = json_encode(['Plane', 'Phenomenon', 'Vanguard', 'Conspiracy', 'Dungeon']);
        $this->assertContains($hiddenPayload, $bindings);
    }

    public function test_exact_bang_match_disables_hidden_filter(): void
    {
        $out = $this->svc()->search('!"Every Hope Shall Vanish"');
        $sql = strtolower($out['builder']->toSql());
        // Exact match → no default hiding.
        $this->assertStringNotContainsString('not json_overlaps(types', $sql);
    }

    public function test_playtest_hidden_by_default(): void
    {
        $out = $this->svc()->search('lightning');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('`scryfall_cards`.`is_playtest` =', $sql);
    }

    public function test_is_playtest_surfaces_playtest_and_disables_default_hide(): void
    {
        $out = $this->svc()->search('is:playtest');
        $sql = strtolower($out['builder']->toSql());
        // is:playtest emits `is_playtest = 1`; the default hide would
        // emit `is_playtest = 0` — so count of `= 1` clauses tells us
        // the default hide didn't piggyback.
        $this->assertStringContainsString('`scryfall_cards`.`is_playtest` =', $sql);
        $bindings = $out['builder']->getBindings();
        $this->assertContains(true, $bindings, 'is:playtest should set is_playtest=true');
        $this->assertNotContains(false, $bindings, 'default hide should not also be applied');
    }

    public function test_is_play_test_hyphen_alias(): void
    {
        $out = $this->svc()->search('is:play-test');
        $sql = strtolower($out['builder']->toSql());
        $this->assertStringContainsString('`scryfall_cards`.`is_playtest` =', $sql);
    }
}
