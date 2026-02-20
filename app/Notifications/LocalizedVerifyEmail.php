<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;

class LocalizedVerifyEmail extends BaseVerifyEmail
{
    public function __construct(?string $locale = null)
    {
        if ($locale !== null) {
            $this->locale($locale);
        }
    }
}
