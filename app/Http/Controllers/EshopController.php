<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public e-shop catalogue. `Product::scopeVisibleTo()` / `isAccessibleTo()`
 * remain the only access rules (MEMBER_ONLY products require an eligible
 * membership, User::hasEligibleMembership()): guests and signed-in users
 * without one never receive member-only product
 * data — not in the listing, its counts, pagination or detail pages.
 *
 * The "Members only" option (`?access=members`) filters the catalogue for
 * eligible members. For anyone else it shows the public catalogue with a
 * sign-in prompt; for a guest it also records the e-shop as the place to
 * return to after signing in. A member-only product page answers the same
 * prompt (403, no product data) to anyone not eligible to see it.
 */
class EshopController extends Controller
{
    public const ACCESS_MEMBERS = 'members';

    public function index(Request $request): View
    {
        $categories = ProductCategory::query()->where('is_active', true)->orderBy('name')->get();
        $category = $categories->firstWhere('slug', $request->query('category'));
        $membersOnly = $request->query('access') === self::ACCESS_MEMBERS;
        $canSeeMemberProducts = $this->isEligible($request);

        if ($membersOnly && ! $canSeeMemberProducts && $request->user() === null) {
            $request->session()->put('url.intended', route('eshop.index', array_filter([
                'access' => self::ACCESS_MEMBERS,
                'category' => $category?->slug,
            ])));
        }

        $products = Product::query()
            ->select(['id', 'product_category_id', 'name', 'slug', 'sku', 'description', 'price', 'access_type'])
            ->visibleTo($request->user())
            ->with(['category:id,name', 'inventory', 'images'])
            ->when($category, fn ($query) => $query->where('product_category_id', $category->id))
            ->when($membersOnly && $canSeeMemberProducts, fn ($query) => $query->where('access_type', Product::ACCESS_MEMBER_ONLY))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('eshop.index', [
            'products' => $products,
            'categories' => $categories,
            'selectedCategory' => $category,
            'membersOnly' => $membersOnly && $canSeeMemberProducts,
            'membersPrompt' => $membersOnly && ! $canSeeMemberProducts,
            'eligibleMember' => $canSeeMemberProducts,
        ]);
    }

    public function show(Request $request, Product $product): View|Response
    {
        if (! $product->is_active) {
            abort(404);
        }

        if (! $product->isAccessibleTo($request->user())) {
            if ($request->user() === null) {
                $request->session()->put('url.intended', route('eshop.show', $product));
            }

            return response()->view('eshop.members-only', [], 403);
        }

        $product->load(['category', 'images', 'inventory']);

        return view('eshop.show', ['product' => $product]);
    }

    /**
     * Same rule as Product::scopeVisibleTo(): an eligible membership.
     */
    private function isEligible(Request $request): bool
    {
        return $request->user()?->hasEligibleMembership() ?? false;
    }
}
