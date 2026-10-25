<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The editor's own landing page (admin may open it too). A hub linking to
     * the existing `/admin/*` sections the role holds a permission for; each
     * of those sections is independently Policy-gated.
     */
    public function show(): View
    {
        return view('editor.dashboard');
    }
}
