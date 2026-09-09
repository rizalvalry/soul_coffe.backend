{{--
    Map point picker for the Lokasi form.

    WHY THIS EXISTS
    ---------------
    An administrator was expected to type latitude and longitude by hand, which meant leaving the
    panel, finding the spot on some other map, copying two long decimals, and getting the order
    right — and a swapped pair puts a geofence in the sea. Here they search the place or click the
    map, and the two fields fill themselves.

    HOW IT AVOIDS A BUILD STEP
    --------------------------
    Leaflet and its CSS come from a CDN, and the glue is Alpine — which Filament already ships.
    Nothing here needs npm or Vite, consistent with the rest of this panel (see the header of
    public/css/bsi-bw.css for what happens when that assumption is broken).

    The map WRITES to the lat/lng form fields through $wire.set, and READS them back when they are
    edited by hand, so neither input is the single source of truth — they stay in step either way.
    Geocoding is Nominatim (OpenStreetMap); it is called only when the operator presses search, so
    a slow or unreachable geocoder never blocks opening the form.
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
@endonce

<div
    x-data="{
        map: null,
        marker: null,
        search: '',
        searching: false,
        message: '',

        // Jakarta as the opening view: this operation is in DKI, and a world map would make
        // every new location start with a pan across the planet.
        fallback: { lat: -6.2088, lng: 106.8456, zoom: 12 },

        init() {
            // Leaflet is deferred, so wait for it rather than assuming it has parsed.
            const start = () => (window.L ? this.build() : setTimeout(start, 60));
            start();
        },

        build() {
            const lat = parseFloat($wire.get('{{ $latPath }}'));
            const lng = parseFloat($wire.get('{{ $lngPath }}'));
            const hasPoint = Number.isFinite(lat) && Number.isFinite(lng) && (lat !== 0 || lng !== 0);

            this.map = L.map($refs.map).setView(
                hasPoint ? [lat, lng] : [this.fallback.lat, this.fallback.lng],
                hasPoint ? 17 : this.fallback.zoom,
            );

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(this.map);

            if (hasPoint) {
                this.place(lat, lng, false);
            }

            this.map.on('click', (e) => this.place(e.latlng.lat, e.latlng.lng, true));

            // Someone typing into the lat/lng inputs by hand is still allowed; the pin follows.
            $watch('$wire.{{ $latPath }}', () => this.syncFromFields());
            $watch('$wire.{{ $lngPath }}', () => this.syncFromFields());
        },

        place(lat, lng, write) {
            const point = [lat, lng];

            if (this.marker) {
                this.marker.setLatLng(point);
            } else {
                this.marker = L.marker(point, { draggable: true }).addTo(this.map);
                // Dragging is the fine adjustment after the click that got you close.
                this.marker.on('dragend', () => {
                    const p = this.marker.getLatLng();
                    this.write(p.lat, p.lng);
                });
            }

            if (write) {
                this.write(lat, lng);
            }
        },

        write(lat, lng) {
            // Seven decimals is exactly what decimal(10,7) stores; more would be rounded away
            // by the database and make the form disagree with what was saved.
            $wire.set('{{ $latPath }}', Number(lat).toFixed(7));
            $wire.set('{{ $lngPath }}', Number(lng).toFixed(7));
            this.message = '';
        },

        syncFromFields() {
            const lat = parseFloat($wire.get('{{ $latPath }}'));
            const lng = parseFloat($wire.get('{{ $lngPath }}'));
            if (!this.map || !Number.isFinite(lat) || !Number.isFinite(lng)) return;

            const current = this.marker?.getLatLng();
            // Guard against the loop: our own write() would otherwise re-trigger this watcher.
            if (current && Math.abs(current.lat - lat) < 1e-7 && Math.abs(current.lng - lng) < 1e-7) return;

            this.place(lat, lng, false);
            this.map.setView([lat, lng], Math.max(this.map.getZoom(), 16));
        },

        async find() {
            const q = this.search.trim();
            if (q.length < 3) {
                this.message = 'Ketik minimal 3 huruf nama tempat.';
                return;
            }

            this.searching = true;
            this.message = '';

            try {
                // viewbox + bounded keeps results inside Greater Jakarta, so searching 'Sudirman'
                // does not land on a street of the same name in another province.
                const url = new URL('https://nominatim.openstreetmap.org/search');
                url.searchParams.set('format', 'json');
                url.searchParams.set('limit', '1');
                url.searchParams.set('countrycodes', 'id');
                url.searchParams.set('viewbox', '106.60,-6.00,107.05,-6.40');
                url.searchParams.set('bounded', '1');
                url.searchParams.set('q', q);

                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) throw new Error('geocoder ' + response.status);

                const hits = await response.json();
                if (!hits.length) {
                    this.message = 'Tempat tidak ditemukan. Coba nama lain, atau klik langsung di peta.';
                    return;
                }

                const lat = parseFloat(hits[0].lat);
                const lng = parseFloat(hits[0].lon);
                this.place(lat, lng, true);
                this.map.setView([lat, lng], 17);
                this.message = hits[0].display_name;
            } catch (e) {
                // The map still works without the geocoder, so say that instead of failing hard.
                this.message = 'Pencarian sedang tidak bisa dipakai. Klik langsung di peta untuk menandai titiknya.';
            } finally {
                this.searching = false;
            }
        },
    }"
    class="bsi-map"
    wire:ignore
>
    <div class="bsi-map__bar">
        <input
            type="search"
            class="bsi-input"
            placeholder="Cari nama tempat — mis. Bundaran HI, Blok M Plaza"
            x-model="search"
            @keydown.enter.prevent="find()"
        >
        <button type="button" class="bsi-btn" @click="find()" :disabled="searching">
            <svg class="bsi-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <span x-text="searching ? 'Mencari…' : 'Cari'"></span>
        </button>
    </div>

    <div x-ref="map" class="bsi-map__canvas"></div>

    <p class="bsi-map__hint">
        Klik di peta untuk menandai titik, atau geser pin-nya untuk memperhalus. Kolom Latitude dan
        Longitude di bawah terisi sendiri.
    </p>

    <p class="bsi-map__msg" x-show="message" x-text="message" x-cloak></p>
</div>
