<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Where admin, editor and dev all land after signing in. Deliberately thin —
 * a hub linking to the sections the signed-in role actually holds a
 * permission for (resources/views/admin/dashboard.blade.php decides which,
 * via the same `@can` checks as the admin nav). Hiding a link here is UX
 * only; every section it could link to is independently Policy-gated.
 */
class DashboardController extends Controller
{
    public function show(): View
    {
        return view('admin.dashboard');
    }
}
