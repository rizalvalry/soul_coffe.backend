<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Uploads one image dropped or pasted straight into a news article's body.
 *
 * The rich editor's own "Attach files" button already goes through Livewire's temporary-upload
 * pipeline, which needs no controller of its own. This one exists for the two paths that pipeline
 * cannot reach: an image dragged in from another browser tab, and one pasted after "Copy image" on
 * a browser that hands back a remote URL instead of the bytes (see resources note in
 * `public/js/news/rich-editor-inline-image.js`). Both still end up as a real `<input type=file>`
 * -shaped upload here — this endpoint is not a URL fetcher, it only ever receives bytes the browser
 * already resolved client-side.
 *
 * Scoped to the same two roles as `NewsPostResource` itself (§ AdministratorOnly is deliberately
 * NOT used here — CONTENT_CREATOR must be able to reach this, which is the entire point).
 */
class NewsInlineImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, [Role::ADMINISTRATOR, Role::CONTENT_CREATOR], true)) {
            abort(403, 'Anda tidak memiliki akses untuk aksi ini.');
        }

        try {
            $request->validate([
                // Matches the rich editor's own file-attachment defaults (HasFileAttachments)
                // plus the cover image's ceiling in NewsPostForm — one number to keep in sync.
                'image' => ['required', 'image', 'max:8192'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $path = $request->file('image')->store('news/inline', 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
