<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalogue\AdjustInventory;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Inventory::class);

        $filter = $request->query('filter');
        $filter = in_array($filter, ['low', 'out'], true) ? $filter : null;

        $inventories = Inventory::query()
            ->with('product:id,name,sku,slug')
            ->when($filter === 'low', fn ($query) => $query->lowStock())
            ->when($filter === 'out', fn ($query) => $query->outOfStock())
            ->orderBy('quantity')
            ->paginate(20)
            ->withQueryString();

        return view('admin.inventory.index', [
            'inventories' => $inventories,
            'filter' => $filter,
            'lowStockCount' => Inventory::query()->lowStock()->count(),
            'outOfStockCount' => Inventory::query()->outOfStock()->count(),
        ]);
    }

    public function updateThreshold(Request $request, Inventory $inventory): RedirectResponse
    {
        Gate::authorize('update', $inventory);

        $validated = $request->validate([
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ]);

        $inventory->update($validated);

        return redirect()->route('admin.inventory.index')->with('status', 'Low-stock threshold updated.');
    }

    public function addStock(Request $request, Inventory $inventory, AdjustInventory $adjust): RedirectResponse
    {
        Gate::authorize('update', $inventory);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $adjust->add($inventory, $validated['amount']);

        return redirect()->route('admin.inventory.index')->with('status', 'Stock added.');
    }

    /**
     * The negative-stock guard is re-checked inside `AdjustInventory::remove()`
     * itself, under a row lock — this catch only turns that into an ordinary
     * validation error instead of a raw exception reaching the browser.
     */
    public function removeStock(Request $request, Inventory $inventory, AdjustInventory $adjust): RedirectResponse
    {
        Gate::authorize('update', $inventory);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $adjust->remove($inventory, $validated['amount']);
        } catch (InsufficientStockException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('admin.inventory.index')->with('status', 'Stock removed.');
    }
}
