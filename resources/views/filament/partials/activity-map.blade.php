{{--
    The live map for Aktivitas Staff.

    The container is wire:ignore and the behaviour lives in public/js/activity-map.js, so a
    refresh updates the markers without throwing away the operator's pan and zoom — and without
    Blade re-rendering the map at all. See the header of that file for why it pulls a payload on a
    timer rather than listening on a socket.

    Leaflet and its CSS come from a CDN with SRI, and Alpine is already in the panel, so nothing
    here needs a build step. Same approach as the Lokasi map picker.
--}}
@once
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    >
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
        defer
    ></script>
    {{-- Not deferred: Alpine's own bundle is deferred, so this has to have defined
         window.activityMap by the time Alpine initialises the component below. --}}
    <script src="{{ asset('js/activity-map.js') }}?v=2026-09-10"></script>
@endonce

<div x-data="activityMap(@js($today))" class="bsi-map" wire:ignore>
    <div x-ref="canvas" class="bsi-map__canvas bsi-map__canvas--tall"></div>
</div>
