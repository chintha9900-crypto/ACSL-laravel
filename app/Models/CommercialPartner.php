<?php

namespace App\Models;

use Database\Factories\CommercialPartnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved external business/organisation with a commercial partnership
 * with Aviation Club International, publicly listed on the website
 * (Phase 1.4A — database foundation only). A flat, uniform list, same shape
 * as `ProductCategory`: `is_active` alone gates visibility — no
 * draft/publish workflow, no taxonomy, no relations.
 */
class CommercialPartner extends Model
{
    /** @use HasFactory<CommercialPartnerFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'logo_path',
        'description',
        'url',
        'display_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
