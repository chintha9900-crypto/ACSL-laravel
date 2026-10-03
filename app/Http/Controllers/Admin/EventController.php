<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\PublishEventListing;
use App\Actions\Content\SaveEventListing;
use App\Actions\Content\UnpublishEventListing;
use App\Actions\Content\UpdateEventImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEventListingRequest;
use App\Http\Requests\Admin\UpdateEventListingRequest;
use App\Models\EventListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin Events CRUD — mirrors Admin\NewsController, adapted for
 * EventListing's two extra fields (`starts_at`, `location`) and its
 * plain-text (not sanitised-HTML) `content` field.
 */
class EventController extends Controller
{
    /**
     * Every event regardless of status — draft/published filtering and the
     * public "published only" rule are separate concerns
     * (EventListing::scopePublished() is never applied here).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EventListing::class);

        $status = $request->query('status');
        $status = in_array($status, EventListing::STATUSES, true) ? $status : null;

        $events = EventListing::query()
            ->select(['id', 'title', 'slug', 'status', 'starts_at', 'location', 'published_at', 'updated_at'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.events.index', [
            'events' => $events,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', EventListing::class);

        return view('admin.events.create');
    }

    public function store(StoreEventListingRequest $request, SaveEventListing $save, UpdateEventImage $updateImage): RedirectResponse
    {
        $event = $save->create($request->safe()->except(['image']), $request->user());

        if ($request->hasFile('image')) {
            $updateImage->handle($event, $request->file('image'));
        }

        return redirect()->route('admin.events.index')->with('status', 'Event created as a draft.');
    }

    public function show(EventListing $event): View
    {
        Gate::authorize('view', $event);

        return view('admin.events.show', ['event' => $event]);
    }

    public function edit(EventListing $event): View
    {
        Gate::authorize('update', $event);

        return view('admin.events.edit', ['event' => $event]);
    }

    public function update(UpdateEventListingRequest $request, EventListing $event, SaveEventListing $save, UpdateEventImage $updateImage): RedirectResponse
    {
        $save->update($event, $request->safe()->except(['image']));

        if ($request->hasFile('image')) {
            $updateImage->handle($event, $request->file('image'));
        }

        return redirect()->route('admin.events.index')->with('status', 'Event updated.');
    }

    public function destroy(EventListing $event): RedirectResponse
    {
        Gate::authorize('delete', $event);

        if ($event->image_path !== null) {
            Storage::disk('public')->delete($event->image_path);
        }

        $event->delete();

        return redirect()->route('admin.events.index')->with('status', 'Event deleted.');
    }

    public function publish(EventListing $event, PublishEventListing $publish): RedirectResponse
    {
        Gate::authorize('publish', $event);

        $publish->handle($event);

        return redirect()->route('admin.events.index')->with('status', 'Event published.');
    }

    public function unpublish(EventListing $event, UnpublishEventListing $unpublish): RedirectResponse
    {
        Gate::authorize('publish', $event);

        $unpublish->handle($event);

        return redirect()->route('admin.events.index')->with('status', 'Event unpublished.');
    }
}
