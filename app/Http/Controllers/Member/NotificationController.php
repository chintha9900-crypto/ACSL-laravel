<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * The signed-in member's own notification inbox, newest first.
     *
     * Ownership comes only from the authenticated user's id; the route accepts
     * no user/member identifier. The unread count is a separate, unpaginated
     * query so it always reflects every notification, not just the current page.
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('member.notifications.index', [
            'notifications' => $user->notifications()->paginate(15),
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one of the member's own notifications as read.
     *
     * The lookup is scoped through the user's own `notifications()` relation, so
     * an id belonging to another member simply matches nothing — there is no
     * separate "does this belong to me" check to forget, and no way to tell
     * from the response whether that id exists at all for someone else.
     */
    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->where('id', $notification)->update(['read_at' => now()]);

        return redirect()->route('member.notifications.show');
    }

    /**
     * Mark every one of the member's own unread notifications as read in one query.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('member.notifications.show')->with('status', 'All notifications marked as read.');
    }
}
