<?php

namespace App\Models;

use App\Notifications\LocalizedResetPassword;
use App\Notifications\LocalizedVerifyEmail;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, MustVerifyEmail, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'journal_title',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function series()
    {
        return $this->hasMany(Series::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (app()->isLocal()) {
            return true;
        }

        return $this->hasAnyRole(['super_admin', 'moderator']);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new LocalizedResetPassword($token, $this->locale));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new LocalizedVerifyEmail($this->locale));
    }
}
