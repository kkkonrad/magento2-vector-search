<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CombinationTechnique implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'rrf', 'label' => __('Reciprocal Rank Fusion (RRF)')],
            ['value' => 'arithmetic_mean', 'label' => __('Score Combination (Arithmetic Mean)')],
            ['value' => 'geometric_mean', 'label' => __('Score Combination (Geometric Mean)')],
            ['value' => 'harmonic_mean', 'label' => __('Score Combination (Harmonic Mean)')]
        ];
    }
}
