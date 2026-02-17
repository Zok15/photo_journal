<?php

namespace App\Policies;

use App\Models\Photo;
use App\Models\User;

/**
 * Политика доступа к фото.
 * Доступ разрешен, если фото принадлежит серии текущего пользователя.
 */
class PhotoPolicy
{
    public function view(User $user, Photo $photo): bool
    {
        return $photo->series()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Photo $photo): bool
    {
        return $photo->series()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, Photo $photo): bool
    {
        return $photo->series()->where('user_id', $user->id)->exists();
    }
}
