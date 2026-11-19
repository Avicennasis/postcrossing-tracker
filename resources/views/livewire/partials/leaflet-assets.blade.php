{{-- Leaflet + OpenStreetMap tiles (MAP_PROVIDER=leaflet). Include once per page before map code. --}}
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<script>
    window.PC_MAP_CONFIG = @js(['tileUrl' => config('maps.tile_url'), 'attribution' => config('maps.tile_attribution')]);
</script>
<script src="{{ asset('js/pc-leaflet.js') }}"></script>
