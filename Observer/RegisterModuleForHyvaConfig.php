<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/** Registers this module's templates for Hyva Tailwind compilation. */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar,
        private readonly DirectoryList $directoryList
    ) {}

    public function execute(Observer $observer): void
    {
        $config = $observer->getData('config');
        if (!$config instanceof DataObject) {
            return;
        }

        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Kkkonrad_VectorSearch');
        if (!is_string($modulePath) || $modulePath === '') {
            return;
        }

        $rootPath = rtrim($this->directoryList->getRoot(), DIRECTORY_SEPARATOR);
        $relativePath = str_starts_with($modulePath, $rootPath . DIRECTORY_SEPARATOR)
            ? substr($modulePath, strlen($rootPath) + 1)
            : $modulePath;
        $extensions = $config->hasData('extensions') ? (array)$config->getData('extensions') : [];

        foreach ($extensions as $extension) {
            if (is_array($extension) && ($extension['src'] ?? null) === $relativePath) {
                return;
            }
        }

        $extensions[] = ['src' => $relativePath];
        $config->setData('extensions', $extensions);
    }
}
