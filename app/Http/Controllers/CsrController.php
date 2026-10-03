<?php

namespace App\Http\Controllers;

use App\Models\CsrProject;
use Illuminate\View\View;

class CsrController extends Controller
{
    /**
     * All published CSR projects, newest first. The listing only truncates
     * each project's content to a preview (with a "View More" link to its
     * own page) once there are more than 5 — with 5 or fewer, the listing
     * shows each project's full content directly, per the approved design.
     */
    public function index(): View
    {
        $projects = CsrProject::query()
            ->select(['id', 'title', 'slug', 'content', 'image_path', 'published_at'])
            ->published()
            ->orderByDesc('published_at')
            ->get();

        return view('csr.index', [
            'projects' => $projects,
            'showPreview' => $projects->count() > 5,
        ]);
    }

    /**
     * A draft (or not-yet-due) project 404s exactly like a non-existent slug
     * — never a different message that would confirm it exists.
     */
    public function show(CsrProject $project): View
    {
        if (! $project->isPubliclyVisible()) {
            abort(404);
        }

        return view('csr.show', ['project' => $project]);
    }
}
