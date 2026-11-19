/*
 * Shared Leaflet helpers (OpenStreetMap tiles). Loaded after
 * vendor/leaflet/leaflet.js by resources/views/livewire/partials/leaflet-assets.blade.php,
 * which also sets window.PC_MAP_CONFIG = { tileUrl, attribution }.
 */
window.PcMap = (function () {
    var cfg = window.PC_MAP_CONFIG || {};

    function create(el, options) {
        var map = L.map(el, Object.assign({ worldCopyJump: true }, options || {}));
        L.tileLayer(cfg.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: cfg.attribution || '&copy; OpenStreetMap contributors'
        }).addTo(map);
        return map;
    }

    function dot(latlng, color, radius, weight) {
        return L.circleMarker(latlng, {
            radius: radius || 6,
            color: '#ffffff',
            weight: weight === undefined ? 1 : weight,
            fillColor: color,
            fillOpacity: 0.9
        });
    }

    // Points along the great circle from a to b ([lat, lng] each), so long
    // routes curve the way the old geodesic Google polyline did.
    function greatCircle(a, b, steps) {
        steps = steps || 64;
        var rad = Math.PI / 180;
        var p1 = a[0] * rad, l1 = a[1] * rad, p2 = b[0] * rad, l2 = b[1] * rad;
        var d = 2 * Math.asin(Math.sqrt(
            Math.pow(Math.sin((p2 - p1) / 2), 2) +
            Math.cos(p1) * Math.cos(p2) * Math.pow(Math.sin((l2 - l1) / 2), 2)
        ));
        if (!d || !isFinite(d) || Math.abs(d - Math.PI) < 1e-9) {
            return [a, b];
        }
        var points = [], prevLng = null;
        for (var i = 0; i <= steps; i++) {
            var f = i / steps;
            var A = Math.sin((1 - f) * d) / Math.sin(d), B = Math.sin(f * d) / Math.sin(d);
            var x = A * Math.cos(p1) * Math.cos(l1) + B * Math.cos(p2) * Math.cos(l2);
            var y = A * Math.cos(p1) * Math.sin(l1) + B * Math.cos(p2) * Math.sin(l2);
            var z = A * Math.sin(p1) + B * Math.sin(p2);
            var lat = Math.atan2(z, Math.sqrt(x * x + y * y)) / rad;
            var lng = Math.atan2(y, x) / rad;
            // Keep longitudes continuous across the antimeridian
            if (prevLng !== null) {
                while (lng - prevLng > 180) { lng -= 360; }
                while (lng - prevLng < -180) { lng += 360; }
            }
            prevLng = lng;
            points.push([lat, lng]);
        }
        return points;
    }

    // Spread markers that share a position onto a 12 px ring at the current
    // zoom so each stays clickable (same idea as the previous Google code).
    function spreadPositions(map, items) {
        var seen = {}, zoom = map.getZoom();
        return items.map(function (it) {
            var key = Number(it.lat).toFixed(3) + ',' + Number(it.lng).toFixed(3);
            var count = seen[key] || 0;
            seen[key] = count + 1;
            if (!count) {
                return L.latLng(it.lat, it.lng);
            }
            var angle = count * (2 * Math.PI / 8);
            var p = map.project([it.lat, it.lng], zoom);
            return map.unproject(L.point(p.x + Math.cos(angle) * 12, p.y + Math.sin(angle) * 12), zoom);
        });
    }

    // Circle markers for items [{lat, lng, color, ...}], re-spread on zoom.
    function spreadMarkers(map, items, build) {
        var markers = items.map(function (it) {
            return build(it).addTo(map);
        });
        function place() {
            spreadPositions(map, items).forEach(function (pos, i) { markers[i].setLatLng(pos); });
        }
        map.on('zoomend', place);
        place();
        return markers;
    }

    return { create: create, dot: dot, greatCircle: greatCircle, spreadMarkers: spreadMarkers };
})();
