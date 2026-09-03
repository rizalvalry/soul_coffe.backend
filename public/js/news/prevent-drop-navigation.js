// Registered panel-wide via AdminPanelProvider (PanelsRenderHook::BODY_END), not just on the news
// pages: a browser's default action for ANY drag/drop or paste it is not otherwise handled — an
// image dragged in from another tab, a file dropped outside a widget's own drop zone — is to
// navigate the tab to that resource. That default is almost never what an admin panel user wants,
// and is exactly the "malah open in new tab" behaviour reported against the news article editor.
//
// This is deliberately last-resort and additive: FilePond and the rich editor's own drag/paste
// handlers run on their own elements during the bubble phase, before this `window`-level listener
// ever sees the event, so their own `preventDefault()`/`stopPropagation()` calls are unaffected —
// this only ever matters for the drops nothing else already claimed.
window.addEventListener('dragover', (event) => event.preventDefault())
window.addEventListener('drop', (event) => event.preventDefault())
