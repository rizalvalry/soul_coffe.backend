// Companion to app/Filament/RichEditorPlugins/InlineImagePastePlugin.php — read that class's
// docblock first for why this exists.
//
// Shares Filament's own bundled TipTap/ProseMirror instead of bundling a second copy (see
// vendor/filament/forms/docs/10-rich-editor.md, "Sharing the bundled TipTap/ProseMirror
// instance") — this file is a plain, unbundled ES module for exactly that reason.

const { Extension } = window.FilamentRichEditor.tiptap.core
const { Plugin, PluginKey } = window.FilamentRichEditor.tiptap.pmState

const UPLOAD_URL = new URL(import.meta.url).searchParams.get('uploadUrl') || ''

const CANNOT_FETCH_MESSAGE =
    'Gambar dari sumber ini tidak bisa diambil otomatis (dibatasi keamanan situs asal). ' +
    'Simpan gambar ke perangkat, lalu unggah lewat tombol "Sisipkan File".'

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''

/** `FilamentNotification` is always on the page — every panel load includes the notifications JS. */
const notify = (message, status = 'danger') => {
    new window.FilamentNotification()
        .title(message)
        .status(status)
        .send()
}

/** A `data:image/...;base64,...` URI never needs a network round trip — decode it in place. */
const blobFromDataUri = (uri) => {
    const [header, base64] = uri.split(',')
    const mimeMatch = header.match(/:(.*?);/)

    if (!mimeMatch || !base64) {
        return null
    }

    const bytes = atob(base64)
    const array = new Uint8Array(bytes.length)

    for (let i = 0; i < bytes.length; i++) {
        array[i] = bytes.charCodeAt(i)
    }

    return new Blob([array], { type: mimeMatch[1] })
}

/**
 * A remote http(s) image can only be read back if its origin serves it with permissive CORS
 * headers (same-origin, such as another image already stored by this CMS, always qualifies).
 * There is no client-side way around a site that does not opt in to this — that is what CORS is
 * for — so a rejected fetch is an expected outcome here, not a bug to retry.
 */
const blobFromRemoteUrl = async (url) => {
    const response = await fetch(url, { credentials: 'omit' })

    if (!response.ok) {
        throw new Error(`Unexpected status ${response.status}`)
    }

    const blob = await response.blob()

    if (!blob.type.startsWith('image/')) {
        throw new Error('Not an image')
    }

    return blob
}

const extensionFromMime = (mime) => mime.split('/')[1]?.split('+')[0] || 'png'

const uploadBlob = async (blob) => {
    const body = new FormData()
    body.append('image', blob, `pasted-image.${extensionFromMime(blob.type)}`)

    const response = await fetch(UPLOAD_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        body,
    })

    if (!response.ok) {
        throw new Error(`Unexpected status ${response.status}`)
    }

    const { url } = await response.json()

    return url
}

/** The first `<img src>` in a pasted/dropped HTML fragment — a copied web image is almost always exactly one. */
const firstImageSrc = (html) => {
    const parsed = new DOMParser().parseFromString(html, 'text/html')

    return parsed.querySelector('img[src]')?.getAttribute('src') ?? null
}

/**
 * Resolves a drop/paste event to an image `src` Filament's own handler will not have already
 * claimed: not a real `File` (that path already works), but a `text/uri-list` link or an
 * `<img src>` inside `text/html`.
 */
const remoteImageSrcFrom = (dataTransfer) => {
    if (!dataTransfer || dataTransfer.files?.length) {
        return null
    }

    const uriList = dataTransfer.getData('text/uri-list')

    if (uriList && /^(https?|data):/i.test(uriList.trim())) {
        return uriList.trim().split('\n')[0]
    }

    const html = dataTransfer.getData('text/html')

    return html ? firstImageSrc(html) : null
}

const insertUploadedImage = (editor, position, url) => {
    editor
        .chain()
        .insertContentAt(position, { type: 'image', attrs: { src: url } })
        .run()
}

/**
 * Claims the event the instant an image reference is recognised — whether or not the upload that
 * follows actually succeeds. That single `preventDefault()` is the fix: it is the only thing
 * standing between this and the browser's own default action for an unclaimed image drop or
 * paste, which is to navigate the tab to it.
 */
const handleRemoteImage = (editor, event, position) => {
    const src = remoteImageSrcFrom(event.clipboardData ?? event.dataTransfer)

    if (!src) {
        return false
    }

    event.preventDefault()
    event.stopPropagation()

    ;(async () => {
        try {
            const blob = src.startsWith('data:')
                ? blobFromDataUri(src)
                : await blobFromRemoteUrl(src)

            if (!blob) {
                throw new Error('Could not read image data')
            }

            const uploadedUrl = await uploadBlob(blob)
            insertUploadedImage(editor, position, uploadedUrl)
        } catch (error) {
            console.error('Inline image from drag/paste could not be uploaded:', error)
            notify(CANNOT_FETCH_MESSAGE)
        }
    })()

    return true
}

export default () =>
    Extension.create({
        name: 'inlineImagePaste',

        addProseMirrorPlugins() {
            const editor = this.editor

            return [
                new Plugin({
                    key: new PluginKey('inlineImagePaste'),
                    props: {
                        handleDrop(editorView, event) {
                            const position = editorView.posAtCoords({
                                left: event.clientX,
                                top: event.clientY,
                            })

                            return handleRemoteImage(editor, event, position?.pos ?? 0)
                        },
                        handlePaste(editorView, event) {
                            return handleRemoteImage(editor, event, editor.state.selection.anchor)
                        },
                    },
                }),
            ]
        },
    })
