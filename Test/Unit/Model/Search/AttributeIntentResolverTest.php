<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\AttributeIntentResolver;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;
use PHPUnit\Framework\TestCase;

class AttributeIntentResolverTest extends TestCase
{
    public function testDetectsAndMatchesColorAttributeIntent(): void
    {
        $resolver = $this->createResolver();

        $intents = $resolver->resolve('niebieskie szorty');

        self::assertSame([
            [
                'attribute' => 'color',
                'group' => 'blue',
                'terms' => ['niebiesk', 'niebieski', 'blue'],
            ],
        ], $intents);
        self::assertTrue($resolver->matchesSource(['attr_color' => 'czarn niebiesk fiolet'], $intents));
        self::assertFalse($resolver->matchesSource(['attr_color' => 'czarn zielon czerwon'], $intents));
    }

    public function testCanUseAnyConfiguredAttribute(): void
    {
        $resolver = $this->createResolver();

        $intents = $resolver->resolve('bawełniana koszulka');

        self::assertSame('material', $intents[0]['attribute']);
        self::assertSame('cotton', $intents[0]['group']);
        self::assertTrue($resolver->matchesSource(['attr_material' => 'Bawełna organiczna'], $intents));
        self::assertFalse($resolver->matchesSource(['attr_material' => 'Poliester'], $intents));
    }

    public function testMultipleAttributeIntentsMustAllMatch(): void
    {
        $resolver = $this->createResolver();
        $intents = $resolver->resolve('niebieska bawełniana koszulka');

        self::assertCount(2, $intents);
        self::assertTrue($resolver->matchesSource([
            'attr_color' => 'niebiesk',
            'attr_material' => 'Bawełna organiczna',
        ], $intents));
        self::assertFalse($resolver->matchesSource([
            'attr_color' => 'niebiesk',
            'attr_material' => 'Poliester',
        ], $intents));
    }

    private function createResolver(): AttributeIntentResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getAttributeIntentRules')->willReturn(
            "color:blue=niebiesk,niebieski,blue\n"
            . "color:green=zielon,zielony,green\n"
            . "material:cotton=bawełn,bawełnian,bawełniana,cotton"
        );

        return new AttributeIntentResolver($config, new PolishStemmer());
    }
}
