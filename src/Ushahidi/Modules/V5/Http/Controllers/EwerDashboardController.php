<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Ushahidi\Modules\V5\Models\Survey;

class EwerDashboardController extends V5Controller
{
    public function show(Request $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'saferworld_staff'], true)) {
            return self::make403('You do not have permission to view dashboard analytics.');
        }

        $survey = Survey::where('name', 'NAGAASHO EWER')->orderByDesc('id')->first();
        if (!$survey) {
            return self::make404('The NAGAASHO EWER survey has not been imported.');
        }

        $formId = (int) $survey->id;
        $categories = $this->postValues($formId, ['Incidence type']);
        $districtValues = $this->postValues($formId, ['District where incidence occurred']);
        $responses = $this->postValues($formId, ['Has any response happened?']);
        $escalations = $this->postValues($formId, ['Are there escalation indicators']);

        $postCategories = $this->singleValueByPost($categories);
        $postDistricts = $this->singleValueByPost($districtValues);
        $incidentMix = $this->incidentMix($postCategories);
        $districts = $this->counts($postDistricts);
        $responsePostIds = $this->matchingPostIds($responses, ['yes']);
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
                ];
            }
            $districtTypes[$district][$category]++;
        }

        foreach ($districtTypes as &$districtType) {
            $districtType['total'] = $districtType['conflict']
                + $districtType['gbv']
                + $districtType['social']
                + $districtType['warning'];
        }
        unset($districtType);
        usort($districtTypes, function ($left, $right) {
            return $right['total'] <=> $left['total'];
        });

        $conflictPostIds = $this->postsInCategory($postCategories, 'conflict');
        $gbvPostIds = $this->postsInCategory($postCategories, 'gbv');
        $warningPostIds = $this->postsInCategory($postCategories, 'warning');

        return response()->json([
            'result' => [
                'form_id' => $formId,
                'reporting_period' => $this->reportingPeriod($formId),
                'kpis' => [
                    'total_reports' => DB::table('posts')->where('form_id', $formId)->count(),
                    'gbv' => $incidentMix['gbv'],
                    'conflicts' => $incidentMix['conflict'],
                    'social_violence' => $incidentMix['social'],
                    'early_warning' => $incidentMix['warning'],
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
                    ['What is the cause of the conflict/incidence?'],
                    $conflictPostIds
                )),
                'conflict_drivers' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    [
                        'If Community/Clan conflict related conflict what are the possible causes?',
                        'What are the possible cause?',
                        'What are the possible causes?',
                    ],
                    $conflictPostIds
                )),
                'conflict_response' => $this->responseByDistrict(
                    $postDistricts,
                    $conflictPostIds,
                    $responsePostIds
                ),
                'escalation_signals' => [
                    'escalating' => count($escalatingPostIds),
                    'stable' => max(0, count($escalationPostIds) - count($escalatingPostIds)),
                ],
                'gbv_nature' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    ['If, Gender Based violence what is the nature of Incidence?'],
                    $gbvPostIds
                )),
                'gbv_districts' => $this->countsByDistrict($postDistricts, $gbvPostIds),
                'survivor_ages' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    ['How old is the victim/survivor/s?', 'How old is the Victim/Survivor/s'],
                    $gbvPostIds
                )),
                'gender_profiles' => [
                    'survivors' => $this->genderPercentage(
                        $formId,
                        ['What is the gender of the victim/survivor/s', 'What is the gender of the Victim/Survivor/s?'],
                        $gbvPostIds,
                        'female'
                    ),
                    'perpetrators' => $this->genderPercentage(
                        $formId,
                        [
                            'What is the gender of the perpetrator/s of the violent crime?',
                            'What is the gender of the Perpetrator/s?',
                        ],
                        $gbvPostIds,
                        'male'
                    ),
                ],
                'social_violence' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    ['If Social  violence which one are you reporting?']
                )),
                'response_coverage' => $this->responseCoverage(
                    $postCategories,
                    $responses
                ),
                'early_warning_districts' => $this->countsByDistrict($postDistricts, $warningPostIds),
                'timeline' => $this->timeline($formId, $postCategories),
                'responding_actors' => $this->namedCounts($this->countsForLabels(
                    $formId,
                    ['Who are the actors responding to the situation on the ground?'],
                    [],
                    true
                )),
            ],
        ]);
    }

    private function postValues($formId, array $labels)
    {
        return DB::table('post_varchar')
            ->join('posts', 'posts.id', '=', 'post_varchar.post_id')
            ->join('form_attributes', 'form_attributes.id', '=', 'post_varchar.form_attribute_id')
            ->where('posts.form_id', $formId)
            ->whereIn('form_attributes.label', $labels)
            ->select(['post_varchar.post_id', 'post_varchar.value'])
            ->get();
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
        $value = strtolower(trim((string) $value));
        if (strpos($value, 'gender') !== false || $value === 'gbv') {
            return 'gbv';
        }
        if (strpos($value, 'conflict') !== false) {
            return 'conflict';
        }
        if (strpos($value, 'warning') !== false || strpos($value, 'environment') !== false) {
            return 'warning';
        }
        if (strpos($value, 'violence') !== false || strpos($value, 'cyber') !== false) {
            return 'social';
        }
        return null;
    }

    private function incidentMix(array $postCategories)
    {
        $counts = ['conflict' => 0, 'gbv' => 0, 'social' => 0, 'warning' => 0];
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
            ->whereIn('form_attributes.label', $labels);

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

    private function responseCoverage(array $postCategories, $responses)
    {
        $responded = array_fill_keys($this->matchingPostIds($responses, ['yes']), true);
        $totals = ['conflict' => 0, 'gbv' => 0, 'social' => 0, 'warning' => 0];
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
            $result[$category] = $this->percentage($responseTotals[$category], $total);
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
        $posts = DB::table('posts')
            ->where('form_id', $formId)
            ->select(['id', 'post_date'])
            ->orderBy('post_date')
            ->get();
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
        $dates = DB::table('posts')
            ->where('form_id', $formId)
            ->selectRaw('MIN(post_date) as first_date, MAX(post_date) as last_date')
            ->first();
        return [
            'start' => $dates && $dates->first_date ? date('Y-m-d', strtotime($dates->first_date)) : null,
            'end' => $dates && $dates->last_date ? date('Y-m-d', strtotime($dates->last_date)) : null,
        ];
    }

    private function percentage($value, $total)
    {
        return $total > 0 ? (int) round(($value / $total) * 100) : 0;
    }
}
