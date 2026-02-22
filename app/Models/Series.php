<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Series extends Model
{
    public const PUBLICATION_DRAFT = 'draft';
    public const PUBLICATION_PENDING_MODERATION = 'pending_moderation';
    public const PUBLICATION_PUBLISHED = 'published';
    public const PUBLICATION_REJECTED = 'rejected';

    public const MODERATION_PENDING = 'pending';
    public const MODERATION_APPROVED = 'approved';
    public const MODERATION_REJECTED = 'rejected';
    public const MODERATION_MANUAL_APPROVED = 'manual_approved';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'is_public',
        'slug',
        'publication_status',
        'moderation_status',
        'moderation_reason',
        'moderation_labels',
        'publication_requested_at',
        'moderated_at',
        'moderated_by',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'moderation_labels' => 'array',
        'publication_requested_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Series $series): void {
            $series->slug = null;
            $series->publication_status ??= $series->is_public
                ? self::PUBLICATION_PUBLISHED
                : self::PUBLICATION_DRAFT;
            $series->moderation_status ??= $series->is_public
                ? self::MODERATION_APPROVED
                : self::MODERATION_APPROVED;
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

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function isPublished(): bool
    {
        return (bool) $this->is_public
            && (string) $this->publication_status === self::PUBLICATION_PUBLISHED;
    }
}
