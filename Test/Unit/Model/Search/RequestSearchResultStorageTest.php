<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Search\RequestSearchResultStorage;
use PHPUnit\Framework\TestCase;

class RequestSearchResultStorageTest extends TestCase
{
    public function testReturnsMarkedIdsForSameQueryAndStore(): void
    {
        $storage = new RequestSearchResultStorage();

        $storage->mark('spodenki dla kobiet', 1, ['2040', 1951]);

        self::assertSame([2040, 1951], $storage->get('spodenki dla kobiet', 1));
    }

    public function testDoesNotReturnIdsForDifferentQueryOrStore(): void
    {
        $storage = new RequestSearchResultStorage();

        $storage->mark('spodenki dla kobiet', 1, [2040, 1951]);

        self::assertNull($storage->get('spodnie dla kobiet', 1));
        self::assertNull($storage->get('spodenki dla kobiet', 2));
    }
}
