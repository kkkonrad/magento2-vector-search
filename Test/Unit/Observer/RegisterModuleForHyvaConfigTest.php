<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Observer;

use Kkkonrad\VectorSearch\Observer\RegisterModuleForHyvaConfig;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Filesystem\DirectoryList;
use PHPUnit\Framework\TestCase;

class RegisterModuleForHyvaConfigTest extends TestCase
{
    public function testRegistersModuleOnlyOnce(): void
    {
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPath')->willReturn('/var/www/html/app/code/Kkkonrad/VectorSearch');
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn('/var/www/html');
        $config = new DataObject(['extensions' => [['src' => 'vendor/example/module']]]);
        $observer = new Observer(['config' => $config]);
        $registration = new RegisterModuleForHyvaConfig($registrar, $directoryList);

        $registration->execute($observer);
        $registration->execute($observer);

        self::assertSame([
            ['src' => 'vendor/example/module'],
            ['src' => 'app/code/Kkkonrad/VectorSearch'],
        ], $config->getData('extensions'));
    }

    public function testIgnoresEventWithoutConfigurationObject(): void
    {
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->expects(self::never())->method('getPath');

        (new RegisterModuleForHyvaConfig(
            $registrar,
            $this->createMock(DirectoryList::class)
        ))->execute(new Observer());
    }
}
