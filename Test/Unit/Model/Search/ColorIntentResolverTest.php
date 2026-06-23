<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\ColorIntentResolver;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;
use PHPUnit\Framework\TestCase;

class ColorIntentResolverTest extends TestCase
{
    public function testDetectsBlueIntentAndMatchesAttrColor(): void
    {
        $resolver = $this->createResolver();

        $intent = $resolver->resolve('niebieskie szorty');

        self::assertSame('blue', $intent['name']);
        self::assertTrue($resolver->matchesSource(['attr_color' => 'czarn niebiesk fiolet'], $intent['terms']));
        self::assertFalse($resolver->matchesSource(['attr_color' => 'czarn zielon czerwon'], $intent['terms']));
    }

    public function testNoColorIntentMatchesEverything(): void
    {
        $resolver = $this->createResolver();

        self::assertTrue($resolver->matchesSource(['attr_color' => 'czarn zielon'], $resolver->resolve('szorty')['terms']));
    }

    private function createResolver(): ColorIntentResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getColorIntentRules')->willReturn("blue=niebiesk,niebieski,niebieskie,blue\ngreen=zielon,zielony,green");

        return new ColorIntentResolver($config, new PolishStemmer());
    }
}
