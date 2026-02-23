<?php

namespace App\Policies;

use App\Models\Series;
use App\Models\User;

/**
 * Политика доступа к сериям.
 * Пользователь видит/меняет только свои серии.
 */
class SeriesPolicy
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'moderator']);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Series $series): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $series->user_id === $user->id || $series->isPublished();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, Series $series): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $series->user_id === $user->id;
    }

    public function delete(User $user, Series $series): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $series->user_id === $user->id;
    }

    public function manualPublish(User $user, Series $series): bool
    {
        unset($series);

        return $this->isAdmin($user);
    }
}
