<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BlogCommentController extends Controller
{
    public function index(): View
    {
        Gate::authorize('moderate', BlogComment::class);

        return view('admin.blog-comments.index', [
            'comments' => BlogComment::query()
                ->where('status', BlogComment::STATUS_PENDING)
                ->with(['user:id,name,email', 'post:id,title,slug'])
                ->oldest()
                ->paginate(20),
        ]);
    }

    public function approve(BlogComment $comment): RedirectResponse
    {
        return $this->moderate($comment, BlogComment::STATUS_APPROVED, 'Comment approved.');
    }

    public function reject(BlogComment $comment): RedirectResponse
    {
        return $this->moderate($comment, BlogComment::STATUS_REJECTED, 'Comment rejected.');
    }

    /**
     * Compare-and-set on `pending`, so a comment is decided once only.
     */
    private function moderate(BlogComment $comment, string $status, string $message): RedirectResponse
    {
        Gate::authorize('moderate', $comment);

        $changed = DB::table('blog_comments')
            ->where('id', $comment->id)
            ->where('status', BlogComment::STATUS_PENDING)
            ->update(['status' => $status, 'moderated_by_user_id' => request()->user()->id, 'moderated_at' => now(), 'updated_at' => now()]);

        return back()->with($changed === 1 ? 'status' : 'warning', $changed === 1 ? $message : 'This comment has already been moderated.');
    }
}
