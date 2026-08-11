<?php

namespace Ushahidi\Tests\Unit\Modules\V5\Http\Controllers;

use ReflectionMethod;
use ReflectionClass;
use Ushahidi\Modules\V5\Http\Controllers\EwerDashboardController;
use Ushahidi\Tests\TestCase;

class EwerDashboardControllerTest extends TestCase
{
    public function testRespondingActorsUseConfiguredSurveyLabels()
    {
        $options = json_encode([
            [
                'name' => 'district_administration_climate',
                'label' => 'District Administration',
            ],
            [
                'name' => 'regional_players_or_actors__e_g_igad_atm_climate',
                'label' => "Community Action Forum (CAF's)",
            ],
            [
                'name' => 'other_responders_clan_conflict_climate',
                'label' => 'Other responders- Clan Conflict',
            ],
        ]);

        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'respondingActorOptionLabels');
        $method->setAccessible(true);
        $actors = $method->invoke($controller, $options);

        $this->assertSame('District Administration', $actors['local_government']);
        $this->assertSame(
            "Community Action Forum (CAF's)",
            $actors['regional_players_or_actors_e_g_igad_atm']
        );
        $this->assertSame(
            'Other responders- Clan Conflict',
            $actors['other_responders_clan_conflict']
        );
    }

    public function testLegacyStringOptionsRemainAvailable()
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'respondingActorOptionLabels');
        $method->setAccessible(true);

        $this->assertSame(
            ['police' => 'Police'],
            $method->invoke($controller, json_encode(['Police']))
        );
    }

    public function testProjectDistrictsIncludeAfgoyeAliasesAndExcludeNonProjectDistricts()
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'projectDistricts');
        $method->setAccessible(true);

        $districts = $method->invoke($controller, [
            1 => 'Afgoi',
            2 => 'Borama',
            3 => 'Bardere',
        ], [
            'afgoye' => 'Afgoye',
            'bardere' => 'Bardere',
        ]);

        $this->assertSame([
            1 => 'Afgoye',
            3 => 'Bardere',
        ], $districts);
    }

    private function controller()
    {
        return (new ReflectionClass(EwerDashboardController::class))->newInstanceWithoutConstructor();
    }
}
