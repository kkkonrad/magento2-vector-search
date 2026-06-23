<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Console\Command;

use Kkkonrad\VectorSearch\Console\Command\SearchConfigSuggestCommand;
use Kkkonrad\VectorSearch\Model\Search\AttributeIntentConfigSuggester;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SearchConfigSuggestCommandTest extends TestCase
{
    public function testPrintsSuggestionsForSelectedAttribute(): void
    {
        $suggester = $this->createMock(AttributeIntentConfigSuggester::class);
        $suggester->expects(self::once())
            ->method('suggest')
            ->with(['color'], 12, 6)
            ->willReturn([
                'fields' => [
                    [
                        'attribute' => 'color',
                        'field' => 'attr_color',
                        'docs' => 148,
                        'samples' => ['niebiesk', 'czarn niebiesk'],
                        'terms' => ['niebiesk', 'czarn'],
                    ],
                ],
                'aliases' => ['color=color'],
                'modes' => ['color=strict'],
                'rules' => [
                    'color:niebiesk=niebiesk',
                    'color:czarn=czarn',
                ],
            ]);

        $tester = new CommandTester(new SearchConfigSuggestCommand(
            $suggester,
            $this->createStateMock()
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--attribute' => ['color'],
            '--sample-size' => '12',
            '--max-terms' => '6',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('VectorSearch config suggestions: fields=1 sample_size=12 max_terms=6', $output);
        self::assertStringContainsString('attr_color docs=148 terms=niebiesk, czarn', $output);
        self::assertStringContainsString('Suggested aliases:', $output);
        self::assertStringContainsString('color=color', $output);
        self::assertStringContainsString('Suggested modes:', $output);
        self::assertStringContainsString('color=strict', $output);
        self::assertStringContainsString('color:niebiesk=niebiesk', $output);
    }

    private function createStateMock(): State
    {
        $state = $this->createMock(State::class);
        $state->method('setAreaCode')->willReturn(null);

        return $state;
    }
}
