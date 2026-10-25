<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlogCommentRequest;
use App\Models\BlogComment;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;

class BlogCommentController extends Controller
{
    /**
     * A member comments on a published post. The comment starts `pending` and
     * stays hidden from everyone else until an admin approves it.
     */
    public function store(StoreBlogCommentRequest $request, BlogPost $post): RedirectResponse
    {
        abort_unless($post->isPubliclyVisible(), 404);

        BlogComment::forceCreate([
            'blog_post_id' => $post->id,
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
            'status' => BlogComment::STATUS_PENDING,
        ]);

        return redirect()->to(route('blog.show', $post->slug).'#comments')
            ->with('status', 'Thank you — your comment will appear once an admin approves it.');
    }
}
