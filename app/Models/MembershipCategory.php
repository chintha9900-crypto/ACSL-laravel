<?php

namespace App\Models;

use Database\Factories\MembershipCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One of exactly three membership categories (docs/database/04 §3). The rows are
 * data; only the immutable single-letter codes are fixed by the architecture.
 */
class MembershipCategory extends Model
{
    /** @use HasFactory<MembershipCategoryFactory> */
    use HasFactory;

    public const CODE_STUDENT = 'S';

    public const CODE_PROFESSIONAL = 'P';

    public const CODE_VETERAN = 'V';

    /**
     * URL slugs accepted by `?category=` (docs/frontend/03 A7) and their codes.
     *
     * @var array<string, string>
     */
    public const SLUG_CODES = [
        'student' => self::CODE_STUDENT,
        'professional' => self::CODE_PROFESSIONAL,
        'veteran' => self::CODE_VETERAN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'display_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * The URL slug for this category's code (`student`, `professional`, `veteran`).
     */
    public function slug(): ?string
    {
        $slug = array_search($this->code, self::SLUG_CODES, true);

        return $slug === false ? null : $slug;
    }

    /**
     * Categories that currently accept new applications, in display order.
     *
     * @param  Builder<MembershipCategory>  $query
     */
    public function scopeAcceptingApplications(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
    }
}
