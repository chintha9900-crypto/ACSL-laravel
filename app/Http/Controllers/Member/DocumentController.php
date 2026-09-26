<?php

namespace App\Http\Controllers\Member;

use App\Actions\Documents\DownloadDocument;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * A member's own document (e.g. their payment evidence). Ownership is never
     * taken from the request: `DocumentPolicy::view()` checks the document's
     * stored `uploaded_by_user_id` against `$request->user()`, so a document
     * belonging to another member is refused regardless of what id is guessed
     * in the URL.
     */
    public function show(Request $request, Document $document, DownloadDocument $download): StreamedResponse
    {
        return $download->handle($request, $document);
    }
}
