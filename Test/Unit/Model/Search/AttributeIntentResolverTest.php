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
                'groups' => ['blue'],
                'terms' => ['niebiesk', 'niebieski', 'blue'],
                'fields' => ['color'],
                'mode' => 'strict',
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

    public function testCanMatchAttributeThroughConfiguredAliasField(): void
    {
        $resolver = $this->createResolver();

        $intents = $resolver->resolve('bawełniana koszulka');

        self::assertTrue($resolver->matchesSource(['attr_fabric' => 'Bawełna organiczna'], $intents));
        self::assertSame([
            [
                'attribute' => 'material',
                'group' => 'cotton',
                'groups' => ['cotton'],
                'mode' => 'strict',
                'fields' => ['attr_material', 'attr_fabric', 'attr_composition'],
                'matched' => true,
                'matched_fields' => ['attr_fabric'],
            ],
        ], $resolver->matchDetails(['attr_fabric' => 'Bawełna organiczna'], $intents));
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

    public function testSoftAttributeMismatchDoesNotFailSourceMatch(): void
    {
        $resolver = $this->createResolver("color=strict\nmaterial=soft");

        $intents = $resolver->resolve('bawełniana koszulka');

        self::assertSame('soft', $intents[0]['mode']);
        self::assertTrue($resolver->matchesSource(['attr_material' => 'Poliester'], $intents));
        self::assertSame('soft', $resolver->matchDetails(['attr_material' => 'Poliester'], $intents)[0]['mode']);
        self::assertFalse($resolver->matchDetails(['attr_material' => 'Poliester'], $intents)[0]['matched']);
    }

    public function testMultipleGroupsForSameAttributeUseOrMatching(): void
    {
        $resolver = $this->createResolver();

        $intents = $resolver->resolve('niebieskie lub czarne szorty');

        self::assertCount(1, $intents);
        self::assertSame('color', $intents[0]['attribute']);
        self::assertSame('blue|black', $intents[0]['group']);
        self::assertSame(['blue', 'black'], $intents[0]['groups']);
        self::assertTrue($resolver->matchesSource(['attr_color' => 'niebiesk'], $intents));
        self::assertTrue($resolver->matchesSource(['attr_color' => 'czarn'], $intents));
        self::assertFalse($resolver->matchesSource(['attr_color' => 'czerwon'], $intents));
    }

    public function testOffAttributeModeSkipsIntent(): void
    {
        $resolver = $this->createResolver("color=strict\nmaterial=off");

        self::assertSame([], $resolver->resolve('bawełniana koszulka'));
    }

    private function createResolver(string $modes = "color=strict\nmaterial=strict"): AttributeIntentResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getAttributeIntentRules')->willReturn(
            "color:blue=niebiesk,niebieski,blue\n"
            . "color:black=czarn,czarne,czarny,black\n"
            . "color:green=zielon,zielony,green\n"
            . "material:cotton=bawełn,bawełnian,bawełniana,cotton"
        );
        $config->method('getAttributeIntentAliases')->willReturn(
            "color=color\n"
            . "material=fabric,composition"
        );
        $config->method('getAttributeIntentModes')->willReturn($modes);

        return new AttributeIntentResolver($config, new PolishStemmer());
    }
}
