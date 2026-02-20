<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;

class LocalizedResetPassword extends BaseResetPassword
{
    public function __construct(string $token, ?string $locale = null)
    {
        parent::__construct($token);

        if ($locale !== null) {
            $this->locale($locale);
        }
    }
}
