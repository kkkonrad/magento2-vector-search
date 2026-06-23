<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Console\Command;

use Kkkonrad\VectorSearch\Console\Command\SearchConfigValidateCommand;
use Kkkonrad\VectorSearch\Model\Search\AttributeIntentConfigValidator;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SearchConfigValidateCommandTest extends TestCase
{
    public function testPrintsValidationReport(): void
    {
        $validator = $this->createMock(AttributeIntentConfigValidator::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with(3)
            ->willReturn([
                'summary' => ['ok' => 1, 'warn' => 0, 'error' => 0],
                'aliases' => ['color' => ['color']],
                'modes' => ['color' => 'strict'],
                'messages' => [],
                'rules' => [
                    [
                        'line' => 1,
                        'attribute' => 'color',
                        'group' => 'blue',
                        'mode' => 'strict',
                        'status' => 'ok',
                        'terms' => ['niebiesk', 'blue'],
                        'warnings' => [],
                        'field_results' => [
                            [
                                'name' => 'attr_color',
                                'exists' => true,
                                'total' => 148,
                                'term_match_count' => 80,
                                'samples' => ['niebiesk', 'czarn niebiesk'],
                            ],
                        ],
                    ],
                ],
            ]);

        $tester = new CommandTester(new SearchConfigValidateCommand(
            $validator,
            $this->createStateMock()
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--sample-size' => '3']));
        $output = $tester->getDisplay();

        self::assertStringContainsString('VectorSearch config validation: ok=1 warn=0 error=0', $output);
        self::assertStringContainsString('color -> attr_color', $output);
        self::assertStringContainsString('color=strict', $output);
        self::assertStringContainsString('OK line=1 color:blue mode=strict terms=niebiesk, blue', $output);
        self::assertStringContainsString('attr_color exists=yes docs=148 term_matches=80', $output);
    }

    private function createStateMock(): State
    {
        $state = $this->createMock(State::class);
        $state->method('setAreaCode')->willReturn(null);

        return $state;
    }
}
