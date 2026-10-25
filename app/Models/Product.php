<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

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
     * The URL to show one of this product's images. A PUBLIC product's images
     * are plain public files. A MEMBER_ONLY product's images live on the
     * private disk and are only reachable through the membership-checked
     * `eshop.images.show` route — pages render that URL only for viewers who
     * may see the product at all.
     */
    public function imageUrl(ProductImage $image): string
    {
        if ($this->access_type === self::ACCESS_MEMBER_ONLY) {
            return route('eshop.images.show', $image);
        }

        return Storage::disk('public')->url($image->image_path);
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
     * `MEMBER_ONLY` products require an eligible membership
     * (User::hasEligibleMembership() — an active account with a currently
     * valid membership term). Being signed in, or holding an admin/editor
     * role, is not enough. Used by the product page, cart and checkout.
     */
    public function isAccessibleTo(?User $user): bool
    {
        if ($this->access_type === self::ACCESS_PUBLIC) {
            return true;
        }

        return $user?->hasEligibleMembership() ?? false;
    }

    /**
     * The storefront listing's version of `isAccessibleTo()` — active
     * products only, and `MEMBER_ONLY` ones included only for a user with an
     * eligible membership. Expressed as SQL (rather than filtering an
     * already-fetched collection) so the listing query itself never fetches,
     * paginates or counts a row the viewer isn't allowed to see.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $isEligibleMember = $user?->hasEligibleMembership() ?? false;

        $query->where('is_active', true)
            ->when(! $isEligibleMember, fn ($q) => $q->where('access_type', self::ACCESS_PUBLIC));
    }
}
