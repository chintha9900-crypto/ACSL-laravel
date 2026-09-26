<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MembershipApplicationController extends Controller
{
    /**
     * Applications, newest first, optionally filtered by one of the four statuses.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MembershipApplication::class);

        $status = $request->query('status');
        $status = in_array($status, MembershipApplication::STATUSES, true) ? $status : null;

        $applications = MembershipApplication::query()
            ->select(['id', 'public_id', 'full_name', 'membership_category_id', 'submitted_at', 'status'])
            ->with('category:id,name')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.membership-applications.index', [
            'applications' => $applications,
            'status' => $status,
        ]);
    }

    /**
     * One application with its applicant details and proof documents.
     */
    public function show(MembershipApplication $application): View
    {
        Gate::authorize('view', $application);

        $application->load([
            'category:id,name',
            'documents' => fn ($query) => $query->orderBy('id'),
            'detailsRequests.documents',
            'membership.terms',
            'membership.user:id,status',
        ]);

        return view('admin.membership-applications.show', ['application' => $application]);
    }
}
