<?php

namespace App\Http\Controllers;

use App\Models\EducationArticle;
use Illuminate\View\View;

/**
 * The public Education page and each article's own page. Only published
 * articles are ever shown; drafts return 404 here (previews live behind the
 * admin routes).
 */
class EducationController extends Controller
{
    public function index(): View
    {
        $articles = EducationArticle::query()
            ->published()
            ->inDisplayOrder()
            ->get(['id', 'title', 'slug', 'short_description', 'featured_image_path', 'featured_image_alt', 'display_column', 'button_label', 'button_url', 'secondary_button_label', 'secondary_button_url']);

        return view('education', [
            'leftArticles' => $articles->where('display_column', EducationArticle::COLUMN_LEFT)->values(),
            'rightArticles' => $articles->where('display_column', EducationArticle::COLUMN_RIGHT)->values(),
        ]);
    }

    public function show(EducationArticle $article): View
    {
        if (! $article->isPublished()) {
            abort(404);
        }

        return view('education.show', ['article' => $article, 'preview' => false]);
    }
}
