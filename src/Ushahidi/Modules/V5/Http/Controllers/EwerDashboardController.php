<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Ushahidi\Modules\V5\Models\Survey;
use App\Support\PartnerPostVisibility;

class EwerDashboardController extends V5Controller
{
    private $visiblePostIds;
    private $dateFrom;
    private $dateTo;

    public function show(Request $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'saferworld_staff', 'saferworld_partner'], true)) {
            return self::make403('You do not have permission to view dashboard analytics.');
        }

        $survey = $this->dashboardSurvey($request);
        if (!$survey) {
            return self::make404('A dashboard-compatible survey has not been imported.');
        }

        $formId = (int) $survey->id;
        $this->dateFrom = $request->query('date_from');
        $this->dateTo = $request->query('date_to');
        $this->visiblePostIds = $this->basePostIds($formId, $user);

        $categories = $this->postValues($formId, $this->fields('incident_type'));
        $postCategories = $this->singleValueByPost($categories);
        $incidentFilter = $this->normalizeCategoryFilter($request->query('incident_type'));
        if ($incidentFilter) {
            $this->visiblePostIds = array_values(array_intersect(
                $this->visiblePostIds,
                $this->postsInCategory($postCategories, $incidentFilter)
            ));
            $categories = $this->postValues($formId, $this->fields('incident_type'));
            $postCategories = $this->singleValueByPost($categories);
        }

        $districtValues = $this->postValues($formId, $this->fields('district'));
        $responses = $this->postValues($formId, $this->fields('response_happened'));
        $escalations = $this->postValues($formId, $this->fields('escalation_indicators'));

        $postDistricts = $this->singleValueByPost($districtValues);
        $incidentMix = $this->incidentMix($postCategories);
        $districts = $this->counts($postDistricts);
        $responsePostIds = $this->matchingPostIds($responses, ['yes']);
        $respondingActors = $this->respondingActorRates(
            $formId,
            $responsePostIds
        );
        $escalatingPostIds = $this->matchingPostIds($escalations, ['yes']);
        $escalationPostIds = $this->allPostIds($escalations);

        $districtTypes = [];
        foreach ($postDistricts as $postId => $district) {
            $category = $this->category($postCategories[$postId] ?? '');
            if (!$category) {
                continue;
            }
            if (!isset($districtTypes[$district])) {
                $districtTypes[$district] = [
                    'name' => $district,
                    'conflict' => 0,
                    'gbv' => 0,
                    'social' => 0,
                    'warning' => 0,
                    'climate' => 0,
                ];
            }
            $districtTypes[$district][$category]++;
        }

        foreach ($districtTypes as &$districtType) {
            $districtType['total'] = $districtType['conflict']
                + $districtType['gbv']
                + $districtType['social']
                + $districtType['warning']
                + $districtType['climate'];
        }
        unset($districtType);
        usort($districtTypes, function ($left, $right) {
            return $right['total'] <=> $left['total'];
        });

        $conflictPostIds = $this->postsInCategory($postCategories, 'conflict');
        $gbvPostIds = $this->postsInCategory($postCategories, 'gbv');
        $warningPostIds = $this->postsInCategory($postCategories, 'warning');
        $postConflictTypes = $this->singleValueByPost(
            $this->postValues($formId, $this->fields('conflict_type'))
        );

        return response()->json([
            'result' => [
                'form_id' => $formId,
                'reporting_period' => $this->reportingPeriod($formId),
                'kpis' => [
                    'total_reports' => $this->scopePosts(
                        DB::table('posts')->where('form_id', $formId)
                    )->count(),
                    'gbv' => $incidentMix['gbv'],
                    'conflicts' => $incidentMix['conflict'],
                    'social_violence' => $incidentMix['social'],
                    'early_warning' => $incidentMix['warning'],
                    'environmental_climate' => $incidentMix['climate'],
                    'response_rate' => $this->percentage(count($responsePostIds), count($this->allPostIds($responses))),
                    'escalation_rate' => $this->percentage(
                        count($escalatingPostIds),
                        count($escalationPostIds)
                    ),
                ],
                'districts' => $this->namedCounts($districts),
                'incident_mix' => $incidentMix,
                'district_types' => array_values($districtTypes),
                'conflict_types' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    $this->fields('conflict_type'),
                    $conflictPostIds
                )),
                'conflict_drivers' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    $this->fields('conflict_drivers'),
                    $conflictPostIds
                )),
                'conflict_response' => $this->responseByValue(
                    $postConflictTypes,
                    $conflictPostIds,
                    $responsePostIds
                ),
                'escalation_signals' => [
                    'escalating' => count($escalatingPostIds),
                    'stable' => max(0, count($escalationPostIds) - count($escalatingPostIds)),
                ],
                'gbv_nature' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    $this->fields('gbv_nature'),
                    $gbvPostIds
                )),
                'gbv_districts' => $this->countsByDistrict($postDistricts, $gbvPostIds),
                'survivor_ages' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    $this->fields('survivor_age'),
                    $gbvPostIds
                )),
                'gender_profiles' => [
                    'survivors' => $this->genderPercentage(
                        $formId,
                        $this->fields('survivor_gender'),
                        $gbvPostIds,
                        'female'
                    ),
                    'perpetrators' => $this->genderPercentage(
                        $formId,
                        $this->fields('perpetrator_gender'),
                        $gbvPostIds,
                        'male'
                    ),
                ],
                'social_violence' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    $this->fields('social_type')
                )),
                'response_coverage' => $this->responseCoverage(
                    $postCategories,
                    $responses
                ),
                'early_warning_districts' => $this->countsByDistrict($postDistricts, $warningPostIds),
                'timeline' => $this->timeline($formId, $postCategories),
                'responding_actors' => $respondingActors,
                'responding_actors_total_yes' => count($responsePostIds),
            ],
        ]);
    }

    private function postValues($formId, array $labels)
    {
        $query = DB::table('post_varchar')
            ->join('posts', 'posts.id', '=', 'post_varchar.post_id')
            ->join('form_attributes', 'form_attributes.id', '=', 'post_varchar.form_attribute_id')
            ->join('form_stages', 'form_stages.id', '=', 'form_attributes.form_stage_id')
            ->where('posts.form_id', $formId)
            ->where('form_stages.form_id', $formId)
            ->select(['post_varchar.post_id', 'post_varchar.value']);
        $this->whereFieldMatches($query, $labels);

        return $this->scopePosts($query)->get();
    }

    private function singleValueByPost($values)
    {
        $result = [];
        foreach ($values as $value) {
            if (!isset($result[$value->post_id]) || trim((string) $result[$value->post_id]) === '') {
                $result[$value->post_id] = trim((string) $value->value);
            }
        }
        return $result;
    }

    private function category($value)
    {
        $value = $this->normalize($value);
        if (strpos($value, 'climate') !== false || strpos($value, 'environment') !== false) {
            return 'climate';
        }
        if (strpos($value, 'gender') !== false || $value === 'gbv') {
            return 'gbv';
        }
        if (strpos($value, 'conflict') !== false || strpos($value, 'clan') !== false) {
            return 'conflict';
        }
        if (strpos($value, 'warning') !== false) {
            return 'warning';
        }
        if (strpos($value, 'violence') !== false || strpos($value, 'cyber') !== false) {
            return 'social';
        }
        return null;
    }

    private function incidentMix(array $postCategories)
    {
        $counts = ['conflict' => 0, 'gbv' => 0, 'social' => 0, 'warning' => 0, 'climate' => 0];
        foreach ($postCategories as $value) {
            $category = $this->category($value);
            if ($category) {
                $counts[$category]++;
            }
        }
        return $counts;
    }

    private function matchingPostIds($values, array $accepted)
    {
        $accepted = array_map('strtolower', $accepted);
        $ids = [];
        foreach ($values as $value) {
            if (in_array(strtolower(trim((string) $value->value)), $accepted, true)) {
                $ids[(int) $value->post_id] = true;
            }
        }
        return array_keys($ids);
    }

    private function allPostIds($values)
    {
        $ids = [];
        foreach ($values as $value) {
            $ids[(int) $value->post_id] = true;
        }
        return array_keys($ids);
    }

    private function postsInCategory(array $postCategories, $category)
    {
        $ids = [];
        foreach ($postCategories as $postId => $value) {
            if ($this->category($value) === $category) {
                $ids[] = (int) $postId;
            }
        }
        return $ids;
    }

    private function counts(array $values)
    {
        $counts = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    private function countsForLabels(
        $formId,
        array $labels,
        array $postIds = [],
        $expandArrays = false
    ) {
        $query = DB::table('post_varchar')
            ->join('posts', 'posts.id', '=', 'post_varchar.post_id')
            ->join('form_attributes', 'form_attributes.id', '=', 'post_varchar.form_attribute_id')
            ->where('posts.form_id', $formId)
            ->join('form_stages', 'form_stages.id', '=', 'form_attributes.form_stage_id')
            ->where('form_stages.form_id', $formId);
        $this->whereFieldMatches($query, $labels);
        $this->scopePosts($query);

        if (!empty($postIds)) {
            $query->whereIn('posts.id', $postIds);
        }

        $counts = [];
        foreach ($query->pluck('post_varchar.value') as $rawValue) {
            $values = [$rawValue];
            if ($expandArrays) {
                $decoded = json_decode($rawValue, true);
                $values = is_array($decoded) ? $decoded : [$rawValue];
            }
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
        }
        arsort($counts);
        return $counts;
    }

    private function namedCounts(array $counts)
    {
        $result = [];
        foreach ($counts as $name => $value) {
            $result[] = ['name' => $name, 'value' => $value];
        }
        return $result;
    }

    private function respondingActorRates($formId, array $responsePostIds)
    {
        if (empty($responsePostIds)) {
            return [];
        }

        $counts = $this->countsForLabels(
            $formId,
            $this->fields('responding_actors'),
            $responsePostIds,
            true
        );
        $totalYes = count($responsePostIds);
        $result = [];

        foreach ($counts as $name => $frequency) {
            $result[] = [
                'name' => $name,
                'frequency' => $frequency,
                'percentage' => $this->percentage($frequency, $totalYes),
            ];
        }

        return $result;
    }

    private function countsByDistrict(array $postDistricts, array $postIds)
    {
        $allowed = array_fill_keys($postIds, true);
        $counts = [];
        foreach ($postDistricts as $postId => $district) {
            if (isset($allowed[$postId])) {
                $counts[$district] = ($counts[$district] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $this->namedCounts($counts);
    }

    private function responseByDistrict(array $postDistricts, array $postIds, array $responsePostIds)
    {
        $allowed = array_fill_keys($postIds, true);
        $responded = array_fill_keys($responsePostIds, true);
        $districts = [];

        foreach ($postDistricts as $postId => $district) {
            if (!isset($allowed[$postId])) {
                continue;
            }
            if (!isset($districts[$district])) {
                $districts[$district] = ['total' => 0, 'responded' => 0];
            }
            $districts[$district]['total']++;
            if (isset($responded[$postId])) {
                $districts[$district]['responded']++;
            }
        }

        $result = [];
        foreach ($districts as $name => $values) {
            $result[] = [
                'name' => $name,
                'value' => $this->percentage($values['responded'], $values['total']),
            ];
        }
        usort($result, function ($left, $right) {
            return $right['value'] <=> $left['value'];
        });
        return $result;
    }

    private function responseByValue(array $valuesByPost, array $postIds, array $responsePostIds)
    {
        $allowed = array_fill_keys($postIds, true);
        $responded = array_fill_keys($responsePostIds, true);
        $groups = [];

        foreach ($valuesByPost as $postId => $value) {
            if (!isset($allowed[$postId]) || trim((string) $value) === '') {
                continue;
            }

            if (!isset($groups[$value])) {
                $groups[$value] = ['total' => 0, 'responded' => 0];
            }
            $groups[$value]['total']++;
            if (isset($responded[$postId])) {
                $groups[$value]['responded']++;
            }
        }

        $result = [];
        foreach ($groups as $name => $counts) {
            $result[] = [
                'name' => $name,
                'value' => $this->percentage($counts['responded'], $counts['total']),
            ];
        }
        usort($result, function ($left, $right) {
            return $right['value'] <=> $left['value'];
        });
        return $result;
    }

    private function responseCoverage(array $postCategories, $responses)
    {
        $responded = array_fill_keys($this->matchingPostIds($responses, ['yes']), true);
        $totals = ['conflict' => 0, 'gbv' => 0, 'social' => 0, 'warning' => 0, 'climate' => 0];
        $responseTotals = $totals;

        foreach ($postCategories as $postId => $value) {
            $category = $this->category($value);
            if (!$category) {
                continue;
            }
            $totals[$category]++;
            if (isset($responded[$postId])) {
                $responseTotals[$category]++;
            }
        }

        $result = [];
        foreach ($totals as $category => $total) {
            $result[$category] = [
                'percentage' => $this->percentage($responseTotals[$category], $total),
                'count' => $responseTotals[$category],
                'total' => $total,
            ];
        }
        return $result;
    }

    private function genderPercentage($formId, array $labels, array $postIds, $gender)
    {
        $counts = $this->countsForLabels($formId, $labels, $postIds);
        $total = array_sum($counts);
        $matching = 0;
        foreach ($counts as $value => $count) {
            if (strpos(strtolower($value), strtolower($gender)) !== false) {
                $matching += $count;
            }
        }
        return $this->percentage($matching, $total);
    }

    private function timeline($formId, array $postCategories)
    {
        $query = DB::table('posts')
            ->where('form_id', $formId)
            ->select(['id', 'post_date'])
            ->orderBy('post_date');
        $posts = $this->scopePosts($query)->get();
        $months = [];

        foreach ($posts as $post) {
            $timestamp = strtotime($post->post_date);
            if (!$timestamp) {
                continue;
            }
            $month = date('Y-m', $timestamp);
            if (!isset($months[$month])) {
                $months[$month] = [
                    'month' => $month,
                    'conflict' => 0,
                    'gbv' => 0,
                    'social' => 0,
                    'warning' => 0,
                    'climate' => 0,
                    'total' => 0,
                ];
            }
            $category = $this->category($postCategories[$post->id] ?? '');
            if ($category) {
                $months[$month][$category]++;
            }
            $months[$month]['total']++;
        }

        return array_values($months);
    }

    private function reportingPeriod($formId)
    {
        $query = DB::table('posts')
            ->where('form_id', $formId)
            ->selectRaw('MIN(post_date) as first_date, MAX(post_date) as last_date');
        $dates = $this->scopePosts($query)->first();
        return [
            'start' => $dates && $dates->first_date ? date('Y-m-d', strtotime($dates->first_date)) : null,
            'end' => $dates && $dates->last_date ? date('Y-m-d', strtotime($dates->last_date)) : null,
        ];
    }

    private function percentage($value, $total)
    {
        return $total > 0 ? (int) round(($value / $total) * 100) : 0;
    }

    private function scopePosts($query)
    {
        if ($this->visiblePostIds !== null) {
            $query->whereIn('posts.id', $this->visiblePostIds ?: [0]);
        }

        return $query;
    }

    private function dashboardSurvey(Request $request)
    {
        $requestedFormId = (int) $request->query('form_id', 0);
        if ($requestedFormId > 0) {
            return Survey::find($requestedFormId);
        }

        $formId = DB::table('forms')
            ->join('posts', 'posts.form_id', '=', 'forms.id')
            ->join('form_stages', 'form_stages.form_id', '=', 'forms.id')
            ->join('form_attributes', 'form_attributes.form_stage_id', '=', 'form_stages.id')
            ->where(function ($query) {
                $this->whereFieldMatches($query, $this->fields('incident_type'));
            })
            ->groupBy('forms.id')
            ->orderByRaw('MAX(posts.created) DESC')
            ->value('forms.id');

        if ($formId) {
            return Survey::find($formId);
        }

        return Survey::where('name', 'NAGAASHO EWER')->orderByDesc('id')->first()
            ?: Survey::orderByDesc('id')->first();
    }

    private function basePostIds($formId, $user)
    {
        $query = DB::table('posts')->where('form_id', $formId);

        if ($user->role === 'saferworld_partner') {
            PartnerPostVisibility::apply($query, $user);
        }

        $this->applyDateFilters($query);

        return $query->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    private function applyDateFilters($query)
    {
        $dateFrom = $this->dateFrom ? strtotime($this->dateFrom) : false;
        $dateTo = $this->dateTo ? strtotime($this->dateTo) : false;

        if ($dateFrom) {
            $query->where('posts.post_date', '>=', date('Y-m-d 00:00:00', $dateFrom));
        }

        if ($dateTo) {
            $query->where('posts.post_date', '<=', date('Y-m-d 23:59:59', $dateTo));
        }
    }

    private function whereFieldMatches($query, array $identifiers)
    {
        $query->where(function ($fieldQuery) use ($identifiers) {
            foreach ($identifiers as $identifier) {
                $fieldQuery
                    ->orWhere('form_attributes.key', $identifier)
                    ->orWhere('form_attributes.label', $identifier)
                    ->orWhere('form_attributes.config', 'like', '%' . $identifier . '%');
            }
        });
    }

    private function normalizeCategoryFilter($value)
    {
        $value = $this->normalize($value);
        return in_array($value, ['conflict', 'gbv', 'social', 'warning', 'climate'], true)
            ? $value
            : null;
    }

    private function normalize($value)
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $value)), '_');
    }

    private function fields($field)
    {
        $fields = [
            'incident_type' => [
                'Incidence_type',
                'Incidence type',
            ],
            'district' => [
                '_2a_City_where_incidence_occurred',
                '_2b_City_where_incidence_occurred',
                'District where incidence occurred',
                'City where incidence occurred',
                'State where incidence occurred',
                'Region where incidence occurred',
            ],
            'response_happened' => [
                '_10_Has_any_response_happened',
                'Has any response happened?',
                'Has the victim/survivor been reached and supported/referred?',
            ],
            'escalation_indicators' => [
                '_12_Are_there_escalation_indic',
                'Are there escalation indicators',
            ],
            'conflict_type' => [
                'What_is_the_cause_of_the_confl',
                'What is the cause of the conflict/incidence?',
            ],
            'conflict_drivers' => [
                '_15_If_Community_Clan_conflict',
                'If Community/Clan conflict related conflict what are the possible causes?',
                'What are the possible cause?',
                'What are the possible causes?',
            ],
            'gbv_nature' => [
                'If_Gender_Based_violence_what',
                'If, Gender Based violence what is the nature of Incidence?',
            ],
            'survivor_age' => [
                '_43_How_old_is_the_victim_survivor_s',
                'How old is the victim/survivor/s?',
                'How old is the Victim/Survivor/s',
            ],
            'survivor_gender' => [
                '_44_What_is_the_gender_of_the_victim_survivor_s',
                'What is the gender of the victim/survivor/s',
                'What is the gender of the Victim/Survivor/s?',
            ],
            'perpetrator_gender' => [
                'What_is_the_gender_of_the_perpetrator',
                'What is the gender of the perpetrator/s of the violent crime?',
                'What is the gender of the Perpetrator/s?',
            ],
            'social_type' => [
                'If_Social_violence_which_one_are_you_reporting',
                'If Social  violence which one are you reporting?',
            ],
            'responding_actors' => [
                '_10a_Who_are_the_actors_respon',
                '_21a_Who_are_the_actors_respon',
                '_29b_Who_are_the_actors_respon',
                '_38b_Who_are_the_actors_respon',
                '_47c_Who_are_the_actors_respon',
                '_58b_Who_are_the_actors_respon',
                'responded_actors',
                'Who are the actors responding to the situation on the ground?',
            ],
        ];

        return $fields[$field] ?? [$field];
    }
}
