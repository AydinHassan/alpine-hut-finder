<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import { Maximize2, Minimize2 } from 'lucide-vue-next';
import type { HutView } from '@/types';

const props = defineProps<{ huts: HutView[]; origin: [number, number] | null; days: number }>();

const el = ref<HTMLDivElement | null>(null);
const isFull = ref(false);

// "Pseudo" fullscreen — a fixed full-viewport overlay rather than the browser
// Fullscreen API, which is flaky (black backdrop, headless quirks). Leaflet
// must be told to recompute its size once the container has resized.
// Toggling just changes CSS. A ResizeObserver (set up in onMounted) notices the
// container actually resized — after the browser has committed layout — and
// tells Leaflet to recompute size + reload tiles at that exact moment.
function toggleFullscreen() {
    isFull.value = !isFull.value;
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && isFull.value) {
        isFull.value = false;
    }
}
let map: L.Map | undefined;
let hutLayer: L.LayerGroup | undefined;
let meMarker: L.CircleMarker | undefined;

const COLOR: Record<string, string> = { lots: '#059669', some: '#d97706', none: '#9ca3af' };
const tone = (free: number) => (free <= 0 ? 'none' : free < 5 ? 'some' : 'lots');
const webUrl = (w: string) => (/^https?:\/\//.test(w) ? w : `https://${w}`);
const fmtDate = (iso: string) =>
    new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });

function badge(free: number): string {
    return (
        `<div style="display:flex;align-items:center;justify-content:center;width:34px;height:20px;` +
        `border-radius:999px;color:#fff;font:700 11px/1 system-ui,sans-serif;border:1.5px solid #fff;` +
        `box-shadow:0 1px 3px rgba(0,0,0,.45);background:${COLOR[tone(free)]}">${free}</div>`
    );
}

function popup(h: HutView): string {
    const dist = h.distance != null ? ` · ${h.distance.toFixed(0)} km away` : '';
    const meta = `${h.altitude ? h.altitude + ' m' : ''}${dist}`;
    const beds = h.night ? `${h.night.freeBeds} free on ${fmtDate(h.night.date)}` : `up to ${h.maxFree} free in ${props.days} days`;
    const btn = 'display:inline-block;text-decoration:none;border-radius:7px;padding:.25rem .55rem;font-size:.8rem;margin-top:.3rem;';
    const web = h.website
        ? `<a href="${webUrl(h.website)}" target="_blank" rel="noopener" style="${btn}border:1px solid #d1d5db;color:inherit;margin-right:.35rem">Website ↗</a>`
        : '';
    return (
        `<div style="font:14px/1.4 system-ui,sans-serif"><strong>${h.name}</strong>` +
        `<div style="color:#6b7280;font-size:.8rem;margin:.15rem 0 .1rem">${meta}<br>${beds}</div>` +
        `${web}<a href="${h.bookingUrl}" target="_blank" rel="noopener" style="${btn}background:#059669;color:#fff">Book ↗</a></div>`
    );
}

function draw() {
    if (!map || !hutLayer) return;
    map.invalidateSize();
    hutLayer.clearLayers();
    const pts: [number, number][] = [];
    for (const h of props.huts) {
        const icon = L.divIcon({ className: '', html: badge(h.freeNow), iconSize: [34, 20], iconAnchor: [17, 10] });
        L.marker([h.lat, h.lng], { icon }).bindPopup(popup(h)).addTo(hutLayer);
        pts.push([h.lat, h.lng]);
    }
    if (meMarker) {
        map.removeLayer(meMarker);
        meMarker = undefined;
    }
    if (props.origin) {
        meMarker = L.circleMarker(props.origin, { radius: 9, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 })
            .bindTooltip('You')
            .addTo(map);
        pts.push(props.origin);
    }
    if (pts.length) map.fitBounds(pts, { padding: [30, 30], maxZoom: 12 });
}

function initMap() {
    if (!el.value) return;
    map = L.map(el.value, { scrollWheelZoom: false }).setView([47.6, 13.3], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    hutLayer = L.layerGroup().addTo(map);
    draw();
}

// Toggling fullscreen resizes the container. Rather than fight Leaflet's stale
// tile geometry after a resize (invalidateSize proved unreliable across
// browsers), tear the map down and build a fresh one in the now-correctly-sized
// container — identical to the initial mount, which renders fine.
function rebuild() {
    map?.remove();
    map = undefined;
    meMarker = undefined;
    initMap();
}

onMounted(() => {
    initMap();
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    map?.remove();
});

// Rebuild once the browser has committed the new layout (double-rAF).
watch(isFull, () => requestAnimationFrame(() => requestAnimationFrame(rebuild)));
watch(() => [props.huts, props.origin] as const, draw, { deep: true });
</script>

<template>
    <div :class="isFull ? 'fixed inset-0 z-[2000] bg-background' : 'relative'">
        <div ref="el" class="overflow-hidden bg-muted" :class="isFull ? 'h-screen w-screen' : 'h-[460px] w-full rounded-xl border'"></div>
        <button
            type="button"
            class="absolute top-3 right-3 z-[500] flex size-9 items-center justify-center rounded-md border bg-background/90 text-foreground shadow-sm backdrop-blur transition hover:bg-accent"
            :title="isFull ? 'Exit fullscreen' : 'Fullscreen map'"
            @click="toggleFullscreen"
        >
            <Minimize2 v-if="isFull" class="size-4" />
            <Maximize2 v-else class="size-4" />
        </button>
    </div>
</template>
