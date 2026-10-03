<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\SaveCommercialPartner;
use App\Actions\Content\UpdateCommercialPartnerImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCommercialPartnerRequest;
use App\Http\Requests\Admin\UpdateCommercialPartnerRequest;
use App\Models\CommercialPartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin Commercial Partners CRUD — mirrors Admin\NewsController's shape
 * (separate index/create/edit/show pages, same image-upload handling) but
 * uses ProductCategoryController's activate()/deactivate() pattern instead
 * of a publish workflow: there is no `status`/`published_at` here, only a
 * plain `is_active` toggle.
 */
class CommercialPartnerController extends Controller
{
    /**
     * Every partner regardless of `is_active` — the public listing's own
     * "active only" rule is a separate concern, enforced wherever the
     * public page reads from this table, never here.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CommercialPartner::class);

        $active = $request->query('active');
        $active = in_array($active, ['1', '0'], true) ? $active : null;

        $partners = CommercialPartner::query()
            ->select(['id', 'name', 'logo_path', 'display_order', 'is_active', 'updated_at'])
            ->when($active !== null, fn ($query) => $query->where('is_active', $active === '1'))
            ->orderBy('display_order')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.commercial-partners.index', [
            'partners' => $partners,
            'active' => $active,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', CommercialPartner::class);

        return view('admin.commercial-partners.create');
    }

    public function store(StoreCommercialPartnerRequest $request, SaveCommercialPartner $save, UpdateCommercialPartnerImage $updateImage): RedirectResponse
    {
        $partner = $save->create($request->safe()->except(['logo']));

        if ($request->hasFile('logo')) {
            $updateImage->handle($partner, $request->file('logo'));
        }

        return redirect()->route('admin.commercial-partners.index')->with('status', 'Partner created as inactive.');
    }

    public function show(CommercialPartner $partner): View
    {
        Gate::authorize('view', $partner);

        return view('admin.commercial-partners.show', ['partner' => $partner]);
    }

    public function edit(CommercialPartner $partner): View
    {
        Gate::authorize('update', $partner);

        return view('admin.commercial-partners.edit', ['partner' => $partner]);
    }

    public function update(UpdateCommercialPartnerRequest $request, CommercialPartner $partner, SaveCommercialPartner $save, UpdateCommercialPartnerImage $updateImage): RedirectResponse
    {
        $save->update($partner, $request->safe()->except(['logo']));

        if ($request->hasFile('logo')) {
            $updateImage->handle($partner, $request->file('logo'));
        }

        return redirect()->route('admin.commercial-partners.index')->with('status', 'Partner updated.');
    }

    public function destroy(CommercialPartner $partner): RedirectResponse
    {
        Gate::authorize('delete', $partner);

        if ($partner->logo_path !== null) {
            Storage::disk('public')->delete($partner->logo_path);
        }

        $partner->delete();

        return redirect()->route('admin.commercial-partners.index')->with('status', 'Partner deleted.');
    }

    public function activate(CommercialPartner $partner): RedirectResponse
    {
        Gate::authorize('update', $partner);

        $partner->update(['is_active' => true]);

        return redirect()->route('admin.commercial-partners.index')->with('status', 'Partner activated.');
    }

    public function deactivate(CommercialPartner $partner): RedirectResponse
    {
        Gate::authorize('update', $partner);

        $partner->update(['is_active' => false]);

        return redirect()->route('admin.commercial-partners.index')->with('status', 'Partner deactivated.');
    }
}
