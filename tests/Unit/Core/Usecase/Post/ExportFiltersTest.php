<?php

namespace Ushahidi\Tests\Unit\Core\Usecase\Post;

use Ushahidi\Core\Usecase\Post\ExportPost;
use Ushahidi\Tests\TestCase;

class ExportFiltersTest extends TestCase
{
    public function testEachBatchKeepsItsOwnPage()
    {
        $filters = (new ExportPost())->constructFilters(
            ['limit' => 200, 'offset' => 400],
            ['form' => [2], 'status' => ['all'], 'limit' => 30]
        );

        $this->assertSame([
            'orderby' => 'id',
            'order' => 'ASC',
            'form' => [2],
            'status' => ['all'],
            'limit' => 200,
            'offset' => 400,
        ], $filters);
    }

    public function testBatchesKeepAnOrderTheJobSets()
    {
        $filters = (new ExportPost())->constructFilters(
            ['limit' => 200, 'offset' => 0],
            ['orderby' => 'post_date', 'order' => 'DESC']
        );

        $this->assertSame('post_date', $filters['orderby']);
        $this->assertSame('DESC', $filters['order']);
    }
}
