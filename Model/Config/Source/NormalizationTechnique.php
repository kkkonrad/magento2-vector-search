<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class NormalizationTechnique implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'min_max', 'label' => __('Min Max Normalization')],
            ['value' => 'l2', 'label' => __('L2 Normalization')]
        ];
    }
}
