<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which surveys an account may work with.
 *
 * Field monitors and partners are scoped to the surveys assigned to them, so
 * one deployment can carry several surveys without every contributor seeing
 * all of them. Administrators and staff are not scoped and never consult this.
 *
 * An account with no assignment sees nothing rather than everything. That is
 * the safer default for a deployment holding incident reports, but it does
 * mean a restricted account is inert until somebody assigns it a survey, which
 * includes every such account already in the database when this ships.
 */
class UserFormAccess
{
    /**
     * Roles whose access is limited to their assigned surveys.
     */
    public const RESTRICTED_ROLES = ['field_monitor', 'saferworld_partner'];

    public static function isRestricted($user): bool
    {
        return $user
            && isset($user->role)
            && in_array($user->role, self::RESTRICTED_ROLES, true);
    }

    /**
     * Survey ids this user has been granted, empty when none.
     */
    public static function formIds(int $userId): array
    {
        return DB::table('user_forms')
            ->where('user_id', $userId)
            ->pluck('form_id')
            ->map(function ($formId) {
                return (int) $formId;
            })
            ->all();
    }

    /**
     * Limit a posts query to the surveys this user may see.
     *
     * Layered on whatever already narrows the query rather than replacing it:
     * a field monitor still sees only their own submissions, and a partner
     * still sees only the monitor codes assigned to them.
     */
    public static function applyToPosts($query, $user)
    {
        if (!self::isRestricted($user)) {
            return $query;
        }

        $formIds = self::formIds((int) $user->id);
        if (empty($formIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('posts.form_id', $formIds);
    }

    /**
     * Limit a surveys query to the ones this user may open.
     */
    public static function applyToSurveys($query, $user)
    {
        if (!self::isRestricted($user)) {
            return $query;
        }

        $formIds = self::formIds((int) $user->id);
        if (empty($formIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('forms.id', $formIds);
    }

    public static function canUseForm($user, $formId): bool
    {
        if (!self::isRestricted($user)) {
            return true;
        }

        if (!$formId) {
            return false;
        }

        return in_array((int) $formId, self::formIds((int) $user->id), true);
    }
}
