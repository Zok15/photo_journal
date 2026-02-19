<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Series extends Model
{
    protected $fillable = ['user_id', 'title', 'description', 'is_public', 'slug'];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Series $series): void {
            $series->slug = null;
        });

        static::created(function (Series $series): void {
            $series->ensureSlug();
        });

        static::updating(function (Series $series): void {
            if ($series->isDirty('title')) {
                $series->slug = self::buildSlug((string) $series->title, (int) $series->id);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $valueString = (string) $value;

        // Backward compatibility: support old id-based URLs.
        if (($field === null || $field === $this->getRouteKeyName()) && ctype_digit($valueString)) {
            return $this->newQuery()->whereKey((int) $valueString)->firstOrFail();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function ensureSlug(): void
    {
        $expected = self::buildSlug((string) $this->title, (int) $this->id);
        if ($this->slug === $expected) {
            return;
        }

        $this->forceFill(['slug' => $expected])->saveQuietly();
    }

    public static function buildSlug(string $title, int $id): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'series';
        }

        return "{$base}-{$id}";
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}
