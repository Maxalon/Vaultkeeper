<?php

namespace Tests\Unit;

use App\Services\DeckImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-function tests for the description normalizer that strips Quill
 * Delta JSON shipped by Archidekt (and occasionally Moxfield). Reflection
 * lets us exercise the helper without spinning up the DB or HTTP fakes.
 */
class DeckImportDescriptionNormalizerTest extends TestCase
{
    private function invoke(mixed $raw): ?string
    {
        // The helper doesn't touch any constructor dependency, so skip the
        // constructor entirely rather than spin up four unused mocks.
        $svc = (new ReflectionClass(DeckImportService::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($svc, 'normalizeImportedDescription');
        $m->setAccessible(true);
        return $m->invoke($svc, $raw);
    }

    public function test_null_passes_through(): void
    {
        $this->assertNull($this->invoke(null));
    }

    public function test_empty_quill_delta_string_becomes_null(): void
    {
        $this->assertNull($this->invoke('{"ops":[]}'));
    }

    public function test_quill_delta_with_only_whitespace_inserts_becomes_null(): void
    {
        $this->assertNull($this->invoke('{"ops":[{"insert":"\n"}]}'));
    }

    public function test_filled_quill_delta_string_flattens_to_plain_text(): void
    {
        $delta = '{"ops":[{"insert":"Stax brew. "},{"insert":"Hard lock","attributes":{"bold":true}},{"insert":" by turn 4.\n"}]}';
        $this->assertSame('Stax brew. Hard lock by turn 4.', $this->invoke($delta));
    }

    public function test_already_decoded_quill_delta_array_flattens(): void
    {
        $delta = ['ops' => [
            ['insert' => 'Hello '],
            ['insert' => 'world'],
        ]];
        $this->assertSame('Hello world', $this->invoke($delta));
    }

    public function test_quill_delta_skips_embed_inserts(): void
    {
        // Image embeds come through as `insert: { image: "..." }` — drop them
        // rather than serializing the embed object into the description.
        $delta = '{"ops":[{"insert":"Before "},{"insert":{"image":"https://example.com/x.png"}},{"insert":" after"}]}';
        $this->assertSame('Before  after', $this->invoke($delta));
    }

    public function test_plain_string_description_is_trimmed_and_returned(): void
    {
        $this->assertSame('Mono-red aggro.', $this->invoke('  Mono-red aggro.  '));
    }

    public function test_blank_string_becomes_null(): void
    {
        $this->assertNull($this->invoke('   '));
    }

    public function test_non_quill_json_string_is_left_alone(): void
    {
        // Looks like JSON but isn't a Delta — leave it for the user to see.
        $this->assertSame('{"foo":"bar"}', $this->invoke('{"foo":"bar"}'));
    }
}
