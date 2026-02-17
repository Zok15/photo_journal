<?php

namespace App\Support;

use App\Models\Series;
use Illuminate\Support\Facades\Cache;

/**
 * Утилита версионирования ключей кеша ответов по сериям.
 *
 * Вместо удаления многих ключей мы "поднимаем версию" пользователя/серии,
 * и старые ключи автоматически перестают использоваться.
 */
class SeriesResponseCache
{
    public const USER_VERSION_PREFIX = 'series:cache:user-version:';

    public const SERIES_VERSION_PREFIX = 'series:cache:series-version:';

    public static function indexKey(int $userId, array $payload): string
    {
        ksort($payload);

        return 'series:index:user:'.$userId
            .':v:'.self::userVersion($userId)
            .':'.sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function showKey(int $userId, int $seriesId, array $payload): string
    {
        ksort($payload);

        return 'series:show:user:'.$userId
            .':series:'.$seriesId
            .':uv:'.self::userVersion($userId)
            .':sv:'.self::seriesVersion($seriesId)
            .':'.sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function bumpUser(int $userId): int
    {
        $key = self::USER_VERSION_PREFIX.$userId;
        // Инициализируем ключ перед increment, если его еще нет.
        Cache::add($key, 1);

        return (int) Cache::increment($key);
    }

    public static function bumpSeries(Series|int $series): int
    {
        $seriesId = $series instanceof Series ? (int) $series->id : (int) $series;
        $key = self::SERIES_VERSION_PREFIX.$seriesId;
        // Инициализируем ключ перед increment, если его еще нет.
        Cache::add($key, 1);

        return (int) Cache::increment($key);
    }

    public static function userVersion(int $userId): int
    {
        return (int) Cache::rememberForever(self::USER_VERSION_PREFIX.$userId, static fn (): int => 1);
    }

    public static function seriesVersion(int $seriesId): int
    {
        return (int) Cache::rememberForever(self::SERIES_VERSION_PREFIX.$seriesId, static fn (): int => 1);
    }
}
