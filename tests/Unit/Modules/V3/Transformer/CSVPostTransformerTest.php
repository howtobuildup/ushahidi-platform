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

    public function testItUsesKoboEndTimeAsTheImportedPostDate()
    {
        $transformer = $this->makeTransformer(
            ['end', 'today', '_submission_time', 'title'],
            [0 => null, 1 => null, 2 => null, 3 => 'title']
        );

        $result = $transformer->interact([
            '2026-05-31 16:02:11.574000+03:00',
            '2026-05-31',
            '2026-06-02 09:15:00',
            'Imported incident',
        ]);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result['post_date']);
        $this->assertSame('2026-05-31T16:02:11+03:00', $result['post_date']->format(DATE_ATOM));
    }

    public function testItUsesKoboTodayAsTheDateOnlyFallback()
    {
        $transformer = $this->makeTransformer(
            ['end', 'today', 'title'],
            [0 => null, 1 => null, 2 => 'title']
        );

        $result = $transformer->interact([
            '',
            '2026-05-31',
            'Imported incident',
        ]);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result['post_date']);
        $this->assertSame('2026-05-31 00:00:00 UTC', $result['post_date']->format('Y-m-d H:i:s T'));
    }

    public function testItFallsBackToTodayWhenKoboEndTimeIsInvalid()
    {
        $transformer = $this->makeTransformer(
            ['end', 'today', 'title'],
            [0 => null, 1 => null, 2 => 'title']
        );

        $result = $transformer->interact([
            'not a date',
            '2026-05-31',
            'Imported incident',
        ]);

        $this->assertSame('2026-05-31', $result['post_date']->format('Y-m-d'));
    }

    public function testItIgnoresKoboSubmissionTimeWhenEndAndTodayAreUnavailable()
    {
        $transformer = $this->makeTransformer(
            ['_submission_time', 'title'],
            [0 => null, 1 => 'title']
        );

        $result = $transformer->interact([
            '2026-05-27 12:47:45',
            'Imported incident',
        ]);

        $this->assertArrayNotHasKey('post_date', $result);
    }

    public function testItPreservesAnExplicitlyMappedPostDate()
    {
        $transformer = $this->makeTransformer(
            ['end', 'post_date'],
            [0 => null, 1 => 'post_date']
        );

        $result = $transformer->interact([
            '2026-05-27 12:47:45',
            '2026-06-01 08:30:00',
        ]);

        $this->assertSame('2026-06-01 08:30:00', $result['post_date']);
    }

    public function testItIgnoresInvalidKoboEndAndTodayValues()
    {
        $transformer = $this->makeTransformer(
            ['end', 'today', 'title'],
            [0 => null, 1 => null, 2 => 'title']
        );

        $result = $transformer->interact([
            'not a date',
            'also not a date',
            'Imported incident',
        ]);

        $this->assertArrayNotHasKey('post_date', $result);
    }
}
