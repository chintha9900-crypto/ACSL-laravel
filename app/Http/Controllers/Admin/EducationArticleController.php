<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\DeleteUnreferencedEducationImages;
use App\Actions\Content\PublishEducationArticle;
use App\Actions\Content\SaveEducationArticle;
use App\Actions\Content\UnpublishEducationArticle;
use App\Actions\Content\UpdateEducationFeaturedImage;
use App\Exceptions\EducationArticleNotSavedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEducationArticleRequest;
use App\Http\Requests\Admin\UpdateEducationArticleRequest;
use App\Models\EducationArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

/**
 * Admin and editor Education management, modelled on the Jobs module.
 * Saving never changes an article's status; Publish/Unpublish are explicit.
 * Create/edit run in one transaction with their image changes, and old
 * files are only deleted after it commits.
 */
class EducationArticleController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EducationArticle::class);

        $status = $request->query('status');
        $status = is_string($status) && array_key_exists($status, EducationArticle::STATUSES) ? $status : null;

        $articles = EducationArticle::query()
            ->select(['id', 'title', 'slug', 'display_column', 'sort_order', 'status', 'updated_at'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('display_column')
            ->inDisplayOrder()
            ->paginate(30)
            ->withQueryString();

        return view('admin.education.index', ['articles' => $articles, 'status' => $status]);
    }

    public function create(): View
    {
        Gate::authorize('create', EducationArticle::class);

        return view('admin.education.create');
    }

    public function store(StoreEducationArticleRequest $request, SaveEducationArticle $save, UpdateEducationFeaturedImage $featured): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $save, $featured): void {
                $article = $save->create($request->safe()->except(['featured_image']), $request->user());

                if ($request->hasFile('featured_image')) {
                    $featured->handle($article, $request->file('featured_image'));
                }
            });
        } catch (EducationArticleNotSavedException) {
            return $this->notSaved('The article could not be saved. Nothing was changed — please try again.');
        }

        return redirect()->route('admin.education.index')->with('status', 'Article created as a draft.');
    }

    /**
     * Preview: the article exactly as the public page shows it, drafts
     * included, behind the management permission only.
     */
    public function show(EducationArticle $article): View
    {
        Gate::authorize('view', $article);

        return view('education.show', ['article' => $article, 'preview' => true]);
    }

    public function edit(EducationArticle $article): View
    {
        Gate::authorize('update', $article);

        return view('admin.education.edit', ['article' => $article]);
    }

    public function update(UpdateEducationArticleRequest $request, EducationArticle $article, SaveEducationArticle $save, UpdateEducationFeaturedImage $featured): RedirectResponse
    {
        try {
            $message = DB::transaction(function () use ($request, $article, $save, $featured): string {
                $save->update($article, $request->safe()->except(['featured_image', 'remove_featured_image']), $request->user());

                // A newly uploaded image takes priority over "Remove current image".
                if ($request->hasFile('featured_image')) {
                    $featured->handle($article, $request->file('featured_image'));
                } elseif ($request->boolean('remove_featured_image') && $article->featured_image_path !== null) {
                    $featured->remove($article);

                    return 'Article updated and image removed.';
                }

                return 'Article updated.';
            });
        } catch (EducationArticleNotSavedException) {
            return $this->notSaved('The article could not be updated. Your changes were not saved — please try again.');
        }

        return redirect()->route('admin.education.index')->with('status', $message);
    }

    /**
     * The record is deleted first; its featured image and any inline images
     * no other article uses are removed only after the delete commits.
     */
    public function destroy(EducationArticle $article, DeleteUnreferencedEducationImages $deleteImages): RedirectResponse
    {
        Gate::authorize('delete', $article);

        $featured = $article->featured_image_path;
        $inline = EducationArticle::inlineImagePaths($article->content);

        try {
            DB::transaction(function () use ($article, $featured, $inline, $deleteImages): void {
                if (! $article->delete()) {
                    throw new EducationArticleNotSavedException('The article could not be deleted.');
                }

                DB::afterCommit(function () use ($featured, $inline, $deleteImages): void {
                    if ($featured !== null) {
                        Storage::disk('public')->delete($featured);
                    }

                    $deleteImages->handle($inline);
                });
            });
        } catch (EducationArticleNotSavedException) {
            return $this->notSaved('The article could not be deleted. Please try again.');
        }

        return redirect()->route('admin.education.index')->with('status', 'Article deleted.');
    }

    public function publish(Request $request, EducationArticle $article, PublishEducationArticle $publish): RedirectResponse
    {
        Gate::authorize('publish', $article);

        try {
            $publish->handle($article, $request->user());
        } catch (EducationArticleNotSavedException) {
            return $this->notSaved('The article could not be published. Please try again.');
        }

        return redirect()->back(fallback: route('admin.education.index'))->with('status', 'Article published.');
    }

    public function unpublish(Request $request, EducationArticle $article, UnpublishEducationArticle $unpublish): RedirectResponse
    {
        Gate::authorize('publish', $article);

        try {
            $unpublish->handle($article, $request->user());
        } catch (EducationArticleNotSavedException) {
            return $this->notSaved('The article could not be unpublished. Please try again.');
        }

        return redirect()->back(fallback: route('admin.education.index'))->with('status', 'Article unpublished and returned to draft.');
    }

    /**
     * Upload for an image inserted into article content (the editor's Image
     * button). Same file checks as the featured image; the file only becomes
     * permanent once an article that uses it is saved — otherwise
     * `education:prune-inline-images` removes it after 24 hours.
     */
    public function uploadImage(Request $request, UpdateEducationFeaturedImage $images): JsonResponse
    {
        Gate::authorize('create', EducationArticle::class);

        $config = config('uploads.education_inline_image');
        $request->validate([
            'image' => ['required', File::types($config['extensions'])->max($config['max_kb'])],
        ]);

        $path = $images->store($request->file('image'), EducationArticle::INLINE_IMAGE_DIRECTORY);

        return response()->json(['url' => EducationArticle::inlineImageUrlPrefix().basename($path)], 201);
    }

    private function notSaved(string $message): RedirectResponse
    {
        return redirect()->back(fallback: route('admin.education.index'))->withInput()->withErrors(['article' => $message]);
    }
}
