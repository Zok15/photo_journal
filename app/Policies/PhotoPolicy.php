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
        return $this->ownsPhoto($user, $photo);
    }

    public function update(User $user, Photo $photo): bool
    {
        return $this->ownsPhoto($user, $photo);
    }

    public function delete(User $user, Photo $photo): bool
    {
        return $this->ownsPhoto($user, $photo);
    }

    private function ownsPhoto(User $user, Photo $photo): bool
    {
        // Загружаем серию один раз (если она еще не загружена) и повторно используем
        // relation cache модели Photo для всех последующих проверок policy.
        $photo->loadMissing(['series:id,user_id']);

        $series = $photo->getRelation('series');
        if ($series === null) {
            return false;
        }

        return (int) $series->user_id === (int) $user->id;
    }
}
