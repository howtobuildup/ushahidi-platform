<?php

namespace Ushahidi\Tests\Unit\Modules\V3\Transformer;

use Ushahidi\Contracts\Repository\Entity\PostRepository;
use Ushahidi\Core\Entity\Post;
use Ushahidi\Modules\V3\Transformer\CSVPostTransformer;
use Ushahidi\Tests\TestCase;

class CSVPostTransformerTest extends TestCase
{
    private function makeTransformer(array $columns, array $map): CSVPostTransformer
    {
        $repository = $this->createMock(PostRepository::class);
        $repository
            ->method('getEntity')
            ->willReturn(new Post());

        $transformer = new CSVPostTransformer();
        $transformer->setRepo($repository);
        $transformer->setColumnNames($columns);
        $transformer->setMap($map);
        $transformer->setFixedValues([]);

        return $transformer;
    }

    public function testItConvertsAKoboGpsValueToAnUshahidiPoint()
    {
        $transformer = $this->makeTransformer(['gps'], [0 => 'location']);

        $result = $transformer->interact([
            '4.9146387 45.025121 171.2 27.033',
        ]);

        $this->assertSame([
            [
                'lat' => 4.9146387,
                'lon' => 45.025121,
            ],
        ], $result['values']['location']);
    }

    public function testItUsesThePopulatedGpsVariantFromAnAllVersionsExport()
    {
        $transformer = $this->makeTransformer(
            ['gps', 'gps_001', 'gps_002'],
            [0 => 'location', 1 => null, 2 => null]
        );

        $result = $transformer->interact([
            '',
            '',
            '10.7561922 47.5853649 1507.3 4.75',
        ]);

        $this->assertSame([
            [
                'lat' => 10.7561922,
                'lon' => 47.5853649,
            ],
        ], $result['values']['location']);
    }

    public function testItDoesNotGuessBetweenMultiplePopulatedGpsVariants()
    {
        $transformer = $this->makeTransformer(
            ['gps', 'gps_001', 'gps_002'],
            [0 => 'location', 1 => null, 2 => null]
        );

        $result = $transformer->interact([
            '',
            '4.9146387 45.025121 171.2 27.033',
            '10.7561922 47.5853649 1507.3 4.75',
        ]);

        $this->assertArrayNotHasKey('location', $result['values']);
    }
}
