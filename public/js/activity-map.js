/* ==========================================================================
   Aktivitas Staff — live map behaviour.

   WHY THIS IS A REAL FILE AND NOT AN x-data ATTRIBUTE
   ---------------------------------------------------
   The component needs quotes, regexes and HTML fragments. Written inline in an
   `x-data="…"` attribute, every double quote would have to be smuggled past the HTML
   parser, which is exactly how a working component becomes a silently broken one. A
   plain file also means the browser caches it and a syntax error is visible in the
   console instead of being swallowed as a malformed attribute.

   No build step, consistent with public/css/bsi-bw.css: this file is the artefact.

   WHY IT PULLS INSTEAD OF LISTENING ON A SOCKET
   ---------------------------------------------
   A phone reports roughly once a minute (config soul.location_ping_min_interval_seconds),
   so a WebSocket would deliver nothing a 20-second pull has not already got — and it
   would spend the Pusher budget the refill notifications depend on. For a past date the
   timer never starts at all, because history does not change.
   ========================================================================== */

window.activityMap = function (isToday) {
    return {
        map: null,
        markers: new Map(),
        trail: null,
        trailDots: [],
        timer: null,
        fitted: false,
        live: !!isToday,

        // Jakarta: this operation is in DKI, and an empty map must not open on the ocean.
        fallback: { lat: -6.2088, lng: 106.8456, zoom: 12 },

        init() {
            // Leaflet is loaded deferred, so wait for it rather than assuming it has parsed.
            const start = () => (window.L ? this.build() : setTimeout(start, 60));
            start();
        },

        build() {
            this.map = L.map(this.$refs.canvas).setView(
                [this.fallback.lat, this.fallback.lng],
                this.fallback.zoom
            );

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(this.map);

            this.load();

            // A change of date or of the selected staff member is a new question — reload at
            // once rather than waiting for the timer.
            this.$watch('$wire.selectedStaffId', () => {
                this.fitted = false;
                this.load();
            });
            this.$watch('$wire.date', () => {
                this.fitted = false;
                this.load();
            });

            if (this.live) {
                this.timer = setInterval(() => this.load(), 20000);
            }
        },

        destroy() {
            if (this.timer) {
                clearInterval(this.timer);
            }
        },

        async load() {
            let payload;

            try {
                payload = await this.$wire.mapPayload();
            } catch (e) {
                // A failed refresh leaves the last known markers on screen. Blanking the map
                // because one request timed out would be worse than showing data that carries
                // its own timestamp.
                return;
            }

            this.drawStaff(payload.staff || []);
            this.drawTrail(payload.trail || []);
        },

        ink() {
            const value = getComputedStyle(document.documentElement)
                .getPropertyValue('--bsi-ink')
                .trim();

            return value || '#131211';
        },

        drawStaff(staff) {
            const seen = new Set();

            staff.forEach((person) => {
                seen.add(person.user_id);

                const point = [person.lat, person.lng];
                const existing = this.markers.get(person.user_id);

                if (existing) {
                    existing.setLatLng(point);
                    existing.setStyle(this.styleFor(person));
                    const popup = existing.getPopup();
                    if (popup) {
                        popup.setContent(this.popupFor(person));
                    }
                    return;
                }

                // Circle markers, not pins: the design system is monochrome and drawn in
                // hairlines, so filled-vs-hollow carries "reporting now" against "last known"
                // without reaching for a colour.
                const marker = L.circleMarker(point, this.styleFor(person))
                    .addTo(this.map)
                    .bindPopup(this.popupFor(person));

                marker.on('click', () => this.$wire.selectStaff(person.user_id));

                this.markers.set(person.user_id, marker);
            });

            // Anyone no longer on the board (date changed, staff deactivated) must not be left
            // behind as a ghost marker.
            this.markers.forEach((marker, id) => {
                if (!seen.has(id)) {
                    marker.remove();
                    this.markers.delete(id);
                }
            });

            if (!this.fitted && staff.length) {
                this.map.fitBounds(
                    L.latLngBounds(staff.map((p) => [p.lat, p.lng])),
                    { padding: [32, 32], maxZoom: 16 }
                );
                this.fitted = true;
            }
        },

        styleFor(person) {
            const ink = this.ink();

            return {
                radius: 8,
                color: ink,
                weight: 1.5,
                opacity: person.is_live ? 1 : 0.55,
                fillColor: ink,
                fillOpacity: person.is_live ? 0.85 : 0,
            };
        },

        /**
         * A staff member's name is user data, so everything here goes through esc(). A popup
         * that interpolated raw values would let a name written in the Users form execute in
         * the panel.
         */
        popupFor(person) {
            const esc = (value) =>
                String(value === null || value === undefined ? '—' : value)
                    .split('&').join('&amp;')
                    .split('<').join('&lt;')
                    .split('>').join('&gt;')
                    .split(String.fromCharCode(34)).join('&quot;')
                    .split(String.fromCharCode(39)).join('&#39;');

            const reported = person.reported_at
                ? person.reported_at + (person.is_live ? '' : ' (terakhir)')
                : '—';

            const rows = [
                ['Gerobak', person.cart_code],
                ['Area', person.area],
                ['Lapor', reported],
                ['Transaksi', person.transactions + ' · ' + person.cups + ' cups'],
                ['Akurasi', person.accuracy_m ? person.accuracy_m + ' m' : '—'],
                [
                    'Baterai',
                    person.battery_pct === null || person.battery_pct === undefined
                        ? '—'
                        : person.battery_pct + '%',
                ],
            ];

            const body = rows
                .map(
                    ([label, value]) =>
                        '<span class=' +
                        String.fromCharCode(39) +
                        'bsi-pop__row' +
                        String.fromCharCode(39) +
                        '><em>' +
                        esc(label) +
                        '</em>' +
                        esc(value) +
                        '</span>'
                )
                .join('');

            const q = String.fromCharCode(39);

            return '<div class=' + q + 'bsi-pop' + q + '><strong>' + esc(person.name) + '</strong>' + body + '</div>';
        },

        drawTrail(points) {
            if (this.trail) {
                this.trail.remove();
                this.trail = null;
            }

            this.trailDots.forEach((dot) => dot.remove());
            this.trailDots = [];

            if (!points.length) {
                return;
            }

            const ink = this.ink();

            this.trail = L.polyline(
                points.map((p) => [p.lat, p.lng]),
                { color: ink, weight: 1.5, opacity: 0.6, dashArray: '4 4' }
            ).addTo(this.map);

            points.forEach((p) => {
                const isSale = p.source === 'sale';

                const dot = L.circleMarker([p.lat, p.lng], {
                    radius: isSale ? 5 : 3,
                    color: ink,
                    weight: isSale ? 1.5 : 1,
                    opacity: 0.9,
                    fillColor: ink,
                    // Filled means a cup was actually sold there; hollow is only a position
                    // report.
                    fillOpacity: isSale ? 0.9 : 0,
                })
                    .addTo(this.map)
                    .bindPopup(p.at + (isSale ? ' · transaksi' : ' · posisi'));

                this.trailDots.push(dot);
            });

            if (!this.fitted) {
                this.map.fitBounds(this.trail.getBounds(), { padding: [32, 32], maxZoom: 17 });
                this.fitted = true;
            }
        },
    };
};
