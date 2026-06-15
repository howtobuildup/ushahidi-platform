<?php

namespace Ushahidi\Tests\Unit\Core\Tool\FileReader;

use Ushahidi\Core\Tool\FileReader\CSVHeaders;
use Ushahidi\Tests\TestCase;

class CSVHeadersTest extends TestCase
{
    public function testItMakesDuplicateAndEmptyHeadersUnique()
    {
        $headers = CSVHeaders::makeUnique([
            'District',
            'District',
            '',
            'District',
            ' ',
        ]);

        $this->assertSame([
            'District',
            'District (2)',
            'Unnamed column',
            'District (3)',
            'Unnamed column (2)',
        ], $headers);
    }
}
