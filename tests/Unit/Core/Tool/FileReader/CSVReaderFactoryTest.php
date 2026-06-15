<?php

namespace Ushahidi\Tests\Unit\Core\Tool\FileReader;

use SplTempFileObject;
use Ushahidi\Core\Tool\FileReader\CSVReaderFactory;
use Ushahidi\Tests\TestCase;

class CSVReaderFactoryTest extends TestCase
{
    public function testItDetectsSemicolonDelimitedFiles()
    {
        $file = new SplTempFileObject();
        $file->fwrite("\"Name\";\"District\"\n\"Report\";\"Baidoa\"\n");

        $reader = (new CSVReaderFactory())->createReader($file);

        $this->assertSame(['Name', 'District'], $reader->fetchOne());
    }
}
