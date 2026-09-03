<?php

namespace App\Filament\RichEditorPlugins;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Illuminate\Support\Facades\Route;

/**
 * Lets the article body accept an image the writer drags in from another browser tab, or pastes
 * after "Copy image" on a browser that hands back a URL instead of raw bytes.
 *
 * Filament's own rich-editor drop/paste handling (`extension-local-files.js`) only recognises a
 * real `File` object (a genuine local-disk drag) or a `data:image/` URI. Dragging an `<img>` off a
 * rendered web page, or a "Copy image" on some browsers, produces neither — it hands the browser a
 * plain http(s) URL. Filament's handler then returns `false`, nothing calls `preventDefault()`,
 * and the browser falls back to its OWN default for an unclaimed image drop/paste: navigating the
 * tab to that image. That is the "malah open in new tab" behaviour reported against this CMS.
 *
 * The JS half claims that case instead: it uploads the image (client-side for a `data:` URI, or a
 * same-origin/CORS-enabled `fetch()` for a remote one) to `admin.news.inline-images.store`, and — if
 * neither is possible (a cross-origin source with no CORS header, which no client-side code can
 * read around) — still calls `preventDefault()` and tells the writer to save the image locally and
 * use "Attach files" instead. Either way, the tab never navigates away again.
 *
 * See `docs/10-rich-editor.md` §"Setting up a TipTap JavaScript extension" for why this is a
 * plugin (Filament's supported extension point) rather than a vendor patch: a change inside
 * vendor/filament is silently discarded by the next `composer update`.
 */
class InlineImagePastePlugin implements RichContentPlugin
{
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    public function getTipTapJsExtensions(): array
    {
        return [
            asset('js/news/rich-editor-inline-image.js').'?uploadUrl='.urlencode(Route::has('admin.news.inline-images.store')
                ? route('admin.news.inline-images.store')
                : ''),
        ];
    }

    public function getEditorTools(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}
