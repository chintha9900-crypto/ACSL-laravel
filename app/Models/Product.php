<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * E-Shop Step 1 — database foundation only (no storefront/cart/checkout
 * logic yet). `access_type` is this product's only visibility/purchase
 * rule: `PUBLIC` products are open to everyone, `MEMBER_ONLY` products
 * require an authenticated, active member.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public const ACCESS_PUBLIC = 'PUBLIC';

    public const ACCESS_MEMBER_ONLY = 'MEMBER_ONLY';

    /**
     * @var list<string>
     */
    public const ACCESS_TYPES = [self::ACCESS_PUBLIC, self::ACCESS_MEMBER_ONLY];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_category_id',
        'name',
        'slug',
        'sku',
        'description',
        'price',
        'access_type',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('display_order');
    }

    /**
     * @return HasOne<Inventory, $this>
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /**
     * `cart_items.product_id` is `RESTRICT` on delete (docs/database/12
     * §3.2) — a product referenced here cannot be deleted at the database
     * layer. `Admin\ProductController::destroy()` checks this first so that
     * case is a clear admin-facing message, never a raw `QueryException`.
     *
     * @return HasMany<CartItem, $this>
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * The e-shop's only access rule: `PUBLIC` products are open to everyone;
     * `MEMBER_ONLY` products require an authenticated, active member — a
     * suspended/pending account does not qualify even if logged in.
     */
    public function isAccessibleTo(?User $user): bool
    {
        if ($this->access_type === self::ACCESS_PUBLIC) {
            return true;
        }

        return $user !== null && $user->status === 'active';
    }

    /**
     * The storefront listing's version of `isAccessibleTo()` — active
     * products only, and `MEMBER_ONLY` ones included only when `$user` is an
     * authenticated, active member. Expressed as SQL (rather than filtering
     * an already-fetched collection) so the listing query itself never
     * fetches, paginates or counts a row the viewer isn't allowed to see.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $isActiveMember = $user !== null && $user->status === 'active';

        $query->where('is_active', true)
            ->when(! $isActiveMember, fn ($q) => $q->where('access_type', self::ACCESS_PUBLIC));
    }
}
