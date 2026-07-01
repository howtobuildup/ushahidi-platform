<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PartnerPostVisibility
{
    private const MONITOR_FIELD_IDENTIFIERS = [
        '_1_Field_Monitor_Code',
        'Field Monitor Code',
        'field_monitor_code',
    ];

    public static function monitorCodes(int $partnerUserId): array
    {
        return DB::table('partner_field_monitors')
            ->join('field_monitors', 'field_monitors.id', '=', 'partner_field_monitors.field_monitor_id')
            ->where('partner_field_monitors.partner_user_id', $partnerUserId)
            ->where('field_monitors.active', true)
            ->pluck('field_monitors.code')
            ->map(function ($code) {
                return trim((string) $code);
            })
            ->filter()
            ->values()
            ->all();
    }

    public static function apply($query, $user)
    {
        $codes = self::monitorCodes((int) $user->id);
        if (empty($codes)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (Builder $monitorQuery) use ($codes) {
            $monitorQuery
                ->select(DB::raw(1))
                ->from('post_varchar')
                ->join(
                    'form_attributes',
                    'form_attributes.id',
                    '=',
                    'post_varchar.form_attribute_id'
                )
                ->whereColumn('post_varchar.post_id', 'posts.id')
                ->whereIn('post_varchar.value', $codes)
                ->where(function (Builder $fieldQuery) {
                    foreach (self::MONITOR_FIELD_IDENTIFIERS as $identifier) {
                        $fieldQuery
                            ->orWhere('form_attributes.key', $identifier)
                            ->orWhere('form_attributes.label', $identifier)
                            ->orWhere('form_attributes.config', 'like', '%' . $identifier . '%');
                    }
                });
        });
    }

    public static function canViewPost($user, int $postId): bool
    {
        if (!$postId) {
            return false;
        }

        $query = DB::table('posts')->where('posts.id', $postId);
        return self::apply($query, $user)->exists();
    }
}
