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

    public function testAffirmativePostIdsAcceptBranchSuffixedChoiceNames()
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'affirmativePostIds');
        $method->setAccessible(true);

        // The climate branch names its options yes_climate and no_climate,
        // so matching a bare "yes" counted none of them and the response
        // gauge read 0% against records that had in fact been answered.
        $values = [
            (object) ['post_id' => 1, 'value' => 'yes'],
            (object) ['post_id' => 2, 'value' => 'Yes'],
            (object) ['post_id' => 3, 'value' => 'yes_climate'],
            (object) ['post_id' => 4, 'value' => 'no'],
            (object) ['post_id' => 5, 'value' => 'no_climate'],
            (object) ['post_id' => 6, 'value' => 'No'],
        ];

        $this->assertSame([1, 2, 3], $method->invoke($controller, $values));
    }

    public function testAffirmativePostIdsRejectValuesThatMerelyBeginWithYes()
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'affirmativePostIds');
        $method->setAccessible(true);

        // Only the choice-name stem counts. Free text that happens to start
        // with the same letters is not an answer of yes.
        $values = [
            (object) ['post_id' => 1, 'value' => 'yesterday'],
            (object) ['post_id' => 2, 'value' => 'Yes, but only partially'],
        ];

        $this->assertSame([2], $method->invoke($controller, $values));
    }

    private function controller()
    {
        return (new ReflectionClass(EwerDashboardController::class))->newInstanceWithoutConstructor();
    }
}
