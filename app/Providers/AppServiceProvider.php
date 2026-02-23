<?php

namespace App\Providers;

use App\Models\Photo;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Policies\SeriesPolicy;
use App\Policies\TagPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Series::class, SeriesPolicy::class);
        Gate::policy(Photo::class, PhotoPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $email = urlencode($user->getEmailForPasswordReset());

            return "{$base}/reset-password?token={$token}&email={$email}";
        });

        VerifyEmail::createUrlUsing(function (User $user): string {
            $signedApiUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ]
            );

            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $parts = parse_url($signedApiUrl);
            parse_str((string) ($parts['query'] ?? ''), $query);

            $params = http_build_query([
                'id' => (string) $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
                'expires' => (string) ($query['expires'] ?? ''),
                'signature' => (string) ($query['signature'] ?? ''),
            ]);

            return "{$base}/verify-email?{$params}";
        });
    }
}
