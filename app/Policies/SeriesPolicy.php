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
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Series $series): bool
    {
        return $series->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Series $series): bool
    {
        return $series->user_id === $user->id;
    }

    public function delete(User $user, Series $series): bool
    {
        return $series->user_id === $user->id;
    }
}
