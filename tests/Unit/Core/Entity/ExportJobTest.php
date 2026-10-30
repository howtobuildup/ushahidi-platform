<?php

namespace Ushahidi\Tests\Unit\Core\Entity;

use Ushahidi\Core\Entity\ExportJob;
use Ushahidi\Tests\TestCase;

class ExportJobTest extends TestCase
{
    public function testAnExportCoversEveryStatusUnlessItNamesOne()
    {
        $job = new ExportJob(['filters' => ['form' => [2]]]);

        $this->assertSame(['form' => [2], 'status' => ['all']], $job->getPostFilters());
    }

    public function testAnExportKeepsTheStatusItNames()
    {
        $job = new ExportJob(['filters' => ['form' => [2], 'status' => ['published']]]);

        $this->assertSame(['form' => [2], 'status' => ['published']], $job->getPostFilters());
    }

    public function testAnExportWithNoFiltersCoversEveryStatus()
    {
        $job = new ExportJob([]);

        $this->assertSame(['status' => ['all']], $job->getPostFilters());
    }
}
