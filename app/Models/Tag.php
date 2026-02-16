<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name'];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => self::normalizeTagName($value ?? ''),
        );
    }

    public static function normalizeTagName(string $value): string
    {
        $prepared = Str::of($value)
            ->ascii()
            ->replaceMatches('/([a-z0-9])([A-Z])/', '$1 $2')
            ->trim()
            ->value();

        $words = preg_split('/[^A-Za-z0-9]+/', $prepared) ?: [];
        $words = array_values(array_filter($words, fn ($word): bool => is_string($word) && $word !== ''));
        $words = array_map('strtolower', $words);

        if ($words === []) {
            return 'tag';
        }

        $head = array_shift($words);
        $tail = array_map(fn (string $word): string => ucfirst($word), $words);

        return $head.implode('', $tail);
    }

    public function photos()
    {
        return $this->belongsToMany(Photo::class);
    }

    public function series()
    {
        return $this->belongsToMany(Series::class);
    }
}
