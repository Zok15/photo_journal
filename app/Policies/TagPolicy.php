<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

/**
 * Политика тегов.
 * Сейчас пользователи могут создавать теги, но не редактировать существующие.
 */
class TagPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Tag $tag): bool
    {
        return false;
    }
}
