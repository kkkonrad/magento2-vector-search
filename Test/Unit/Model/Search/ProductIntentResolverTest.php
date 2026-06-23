<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;
use Kkkonrad\VectorSearch\Model\Search\ProductIntentResolver;
use PHPUnit\Framework\TestCase;

class ProductIntentResolverTest extends TestCase
{
    public function testDefaultRulesDetectShortsIntent(): void
    {
        $resolver = $this->createResolver('');

        $intent = $resolver->resolve('spodenki dla kobiet');

        self::assertSame('shorts', $intent['name']);
        self::assertSame(['szort', 'spoden'], $intent['terms']);
    }

    public function testMatchesProductTextAgainstDetectedIntent(): void
    {
        $resolver = $this->createResolver('');
        $terms = $resolver->resolve('spodenki dla kobiet')['terms'];

        self::assertTrue($resolver->matches('Szorty do biegania Erika', $terms));
        self::assertFalse($resolver->matches('Piankowy klocek do jogi Sprite', $terms));
    }

    public function testMatchesStructuredCategoryAndAttributes(): void
    {
        $resolver = $this->createResolver('');
        $terms = $resolver->resolve('spodenki dla kobiet')['terms'];

        self::assertTrue($resolver->matchesSource([
            'name' => 'Erika',
            'category_names' => 'Kobiety Dolne części garderoby Szorty',
            'attr_style_bottom' => 'Dopasowany',
        ], $terms));

        self::assertTrue($resolver->matchesSource([
            'name' => 'Gwen',
            'attr_style_bottom' => ['Warstwa bazowa', 'Szorty rowerowe'],
        ], $terms));

        self::assertFalse($resolver->matchesSource([
            'name' => 'Piankowy klocek do jogi Sprite',
            'category_names' => 'Akcesoria Sprzęt fitness',
            'attr_category_gear' => 'Ćwiczenia',
        ], $terms));
    }

    public function testDoesNotMatchShortIntentTermsAsSubstrings(): void
    {
        $resolver = $this->createResolver('');

        self::assertSame('bottles', $resolver->resolve('butelka na wodę')['name']);
        self::assertSame('shoes', $resolver->resolve('buty do biegania')['name']);

        $terms = $resolver->resolve('mata do jogi')['terms'];
        self::assertFalse($resolver->matchesSource([
            'name' => 'Koszulka treningowa',
            'attr_material' => 'Materiał syntetyczny',
            'embedding_text' => 'Materiał: Poliester',
        ], $terms));
    }

    public function testConfiguredRulesOverrideDefaults(): void
    {
        $resolver = $this->createResolver("accessories=kloc,blok\ncustom=foobar");

        $intent = $resolver->resolve('klocek do jogi');

        self::assertSame('accessories', $intent['name']);
        self::assertSame(['kloc', 'blok'], $intent['terms']);
        self::assertSame('', $resolver->resolve('spodenki dla kobiet')['name']);
    }

    private function createResolver(string $rules): ProductIntentResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getProductIntentRules')->willReturn($rules);

        return new ProductIntentResolver($config, new PolishStemmer());
    }
}
