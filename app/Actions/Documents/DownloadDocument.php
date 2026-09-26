<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Send a private document to an authorised viewer as an attachment. The storage
 * location is never exposed: the URL carries only the document's public id, and
 * every open is recorded in the audit log (docs/architecture/07 §6).
 *
 * Shared by the admin and member document routes so the streaming/authorization/
 * audit logic exists in exactly one place — `DocumentPolicy::view()` (checked
 * here) is what actually decides whether an admin or the owning member may see
 * a given document; this action does not add or duplicate that logic.
 */
class DownloadDocument
{
    public function handle(Request $request, Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        $disk = Storage::disk($document->disk);

        abort_if($document->purged_at !== null || ! $disk->exists($document->storage_path), 404);

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'actor_type' => 'user',
            'event' => 'document.viewed',
            'subject_type' => 'document',
            'subject_id' => $document->id,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'created_at' => now(),
        ]);

        return $disk->download($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
