<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\PublishCsrProject;
use App\Actions\Content\SaveCsrProject;
use App\Actions\Content\UnpublishCsrProject;
use App\Actions\Content\UpdateCsrImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCsrProjectRequest;
use App\Http\Requests\Admin\UpdateCsrProjectRequest;
use App\Models\CsrProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin CSR CRUD — mirrors Admin\NewsController, minus the excerpt field
 * (CsrProject has none).
 */
class CsrController extends Controller
{
    /**
     * Every project regardless of status — draft/published filtering and
     * the public "published only" rule are separate concerns
     * (CsrProject::scopePublished() is never applied here).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CsrProject::class);

        $status = $request->query('status');
        $status = in_array($status, CsrProject::STATUSES, true) ? $status : null;

        $projects = CsrProject::query()
            ->select(['id', 'title', 'slug', 'status', 'published_at', 'updated_at'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.csr.index', [
            'projects' => $projects,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', CsrProject::class);

        return view('admin.csr.create');
    }

    public function store(StoreCsrProjectRequest $request, SaveCsrProject $save, UpdateCsrImage $updateImage): RedirectResponse
    {
        $project = $save->create($request->safe()->except(['image']), $request->user());

        if ($request->hasFile('image')) {
            $updateImage->handle($project, $request->file('image'));
        }

        return redirect()->route('admin.csr.index')->with('status', 'CSR project created as a draft.');
    }

    public function show(CsrProject $project): View
    {
        Gate::authorize('view', $project);

        return view('admin.csr.show', ['project' => $project]);
    }

    public function edit(CsrProject $project): View
    {
        Gate::authorize('update', $project);

        return view('admin.csr.edit', ['project' => $project]);
    }

    public function update(UpdateCsrProjectRequest $request, CsrProject $project, SaveCsrProject $save, UpdateCsrImage $updateImage): RedirectResponse
    {
        $save->update($project, $request->safe()->except(['image']));

        if ($request->hasFile('image')) {
            $updateImage->handle($project, $request->file('image'));
        }

        return redirect()->route('admin.csr.index')->with('status', 'CSR project updated.');
    }

    public function destroy(CsrProject $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        if ($project->image_path !== null) {
            Storage::disk('public')->delete($project->image_path);
        }

        $project->delete();

        return redirect()->route('admin.csr.index')->with('status', 'CSR project deleted.');
    }

    public function publish(CsrProject $project, PublishCsrProject $publish): RedirectResponse
    {
        Gate::authorize('publish', $project);

        $publish->handle($project);

        return redirect()->route('admin.csr.index')->with('status', 'CSR project published.');
    }

    public function unpublish(CsrProject $project, UnpublishCsrProject $unpublish): RedirectResponse
    {
        Gate::authorize('publish', $project);

        $unpublish->handle($project);

        return redirect()->route('admin.csr.index')->with('status', 'CSR project unpublished.');
    }
}
