<?php

namespace Ushahidi\Tests\Unit\Modules\V5\Http\Controllers;

use Mockery;
use ReflectionMethod;
use Illuminate\Support\Facades\Storage;
use Ushahidi\Tests\TestCase;
use App\Bus\Command\CommandBus;
use App\Bus\Query\QueryBus;
use Ushahidi\Modules\V5\Http\Controllers\ExportJobController;

class ExportJobControllerTest extends TestCase
{
    public function testStreamsTheCompleteStoredExport()
    {
        Storage::fake('public');
        $contents = "id,title\n1,Stored response\n";
        Storage::disk('public')->put('csv/export.csv', $contents);

        $controller = new ExportJobController(
            Mockery::mock(QueryBus::class),
            Mockery::mock(CommandBus::class)
        );
        $method = new ReflectionMethod($controller, 'streamExportFromDisk');
        $method->setAccessible(true);
        $response = $method->invoke($controller, 'public', 'csv/export.csv', 15);

        ob_start();
        $response->sendContent();
        $downloaded = ob_get_clean();

        $this->assertSame($contents, $downloaded);
        $this->assertSame((string) strlen($contents), $response->headers->get('Content-Length'));
        $this->assertSame('attachment; filename=export.csv', $response->headers->get('Content-Disposition'));
    }

    public function testRejectsAnEmptyStoredExport()
    {
        Storage::fake('public');
        Storage::disk('public')->put('csv/empty.csv', '');

        $controller = new ExportJobController(
            Mockery::mock(QueryBus::class),
            Mockery::mock(CommandBus::class)
        );
        $method = new ReflectionMethod($controller, 'streamExportFromDisk');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, 'public', 'csv/empty.csv', 16));
    }
}
