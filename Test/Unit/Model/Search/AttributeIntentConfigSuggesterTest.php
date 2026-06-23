<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\OpenSearch\Client;
use Kkkonrad\VectorSearch\Model\Search\AttributeIntentConfigSuggester;
use PHPUnit\Framework\TestCase;

class AttributeIntentConfigSuggesterTest extends TestCase
{
    public function testSuggestsCuratedColorGroupsFromIndexedTokens(): void
    {
        $client = $this->createClientMock([
            'attr_color' => [
                'total' => 4,
                'samples' => ['niebiesk czarn', 'brąz', 'niebiesk'],
            ],
        ]);

        $suggestions = (new AttributeIntentConfigSuggester($client))->suggest(['color'], 25, 5);

        self::assertSame(['color=color'], $suggestions['aliases']);
        self::assertSame(['color=strict'], $suggestions['modes']);
        self::assertContains('color:blue=niebiesk,niebieski,niebieskie,blue,granat,granatowy,navy', $suggestions['rules']);
        self::assertContains('color:black=czarn,czarne,czarny,black', $suggestions['rules']);
        self::assertContains('color:brown=brąz,braz,brązowy,brazowy,brown', $suggestions['rules']);
        self::assertSame(['blue', 'black', 'brown'], $suggestions['fields'][0]['terms']);
        self::assertSame(['niebiesk'], $suggestions['fields'][0]['suggestions'][0]['matched_terms']);
    }

    public function testFallsBackToRawTokensForUnknownAttribute(): void
    {
        $client = $this->createClientMock([
            'attr_size' => [
                'total' => 3,
                'samples' => ['large medium', 'medium small'],
            ],
        ]);

        $suggestions = (new AttributeIntentConfigSuggester($client))->suggest(['size'], 25, 2);

        self::assertSame(['size=size'], $suggestions['aliases']);
        self::assertSame(['size=strict'], $suggestions['modes']);
        self::assertSame(['size:medium=medium', 'size:large=large'], $suggestions['rules']);
        self::assertSame(['medium', 'large'], $suggestions['fields'][0]['terms']);
    }

    /**
     * @param array<string, array{total: int, samples: string[]}> $fieldValues
     */
    private function createClientMock(array $fieldValues): Client
    {
        $client = $this->createMock(Client::class);
        $properties = [];
        foreach (array_keys($fieldValues) as $field) {
            $properties[$field] = ['type' => 'text'];
            $properties[$field . '_id'] = ['type' => 'keyword'];
        }
        $properties['attr_category_names'] = ['type' => 'text'];

        $client->method('getMappingProperties')->willReturn($properties);
        $client->method('sampleFieldValues')->willReturnCallback(
            static fn(string $field): array => $fieldValues[$field] ?? ['total' => 0, 'samples' => []]
        );

        return $client;
    }
}
