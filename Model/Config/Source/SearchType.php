<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SearchType implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'hybrid', 'label' => __('Hybrid (kNN + Lexical)')],
            ['value' => 'knn', 'label' => __('Pure kNN (Semantic Only)')]
        ];
    }
}
