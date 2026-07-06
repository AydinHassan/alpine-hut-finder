<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { BedDouble, ExternalLink, LocateFixed, MapPin, Mountain } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import SearchBox from '@/components/SearchBox.vue';
import DatePicker from '@/components/DatePicker.vue';
import HutMap from '@/components/HutMap.vue';
import type { HutView, Payload } from '@/types';

const props = defineProps<{ payload: Payload }>();

const PRESETS: Record<string, [number, number]> = {
    Innsbruck: [47.2692, 11.4041],
    Salzburg: [47.8095, 13.055],
    Wien: [48.2082, 16.3738],
    Graz: [47.0707, 15.4395],
};

const origin = ref<[number, number] | null>(null);
const originLabel = ref<string | null>(null);
const selectedDate = ref<string | null>(null);
const locating = ref(false);

const dates = computed(() => {
    const out: string[] = [];
    const start = new Date(props.payload.today + 'T00:00:00');
    for (let i = 0; i < props.payload.days; i++) {
        const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`);
    }
    return out;
});

function haversine(a: [number, number], b: [number, number]) {
    const R = 6371;
    const toRad = (x: number) => (x * Math.PI) / 180;
    const dLat = toRad(b[0] - a[0]);
    const dLng = toRad(b[1] - a[1]);
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
}

const huts = computed<HutView[]>(() => {
    let list = props.payload.huts.map((h): HutView => {
        const night = selectedDate.value ? (h.nights.find((n) => n.date === selectedDate.value) ?? null) : null;
        const maxFree = h.nights.reduce((m, n) => Math.max(m, n.freeBeds), 0);
        return {
            ...h,
            distance: origin.value ? haversine(origin.value, [h.lat, h.lng]) : null,
            night,
            maxFree,
            freeNow: night ? night.freeBeds : maxFree,
        };
    });
    if (selectedDate.value) list = list.filter((h) => (h.night?.freeBeds ?? 0) > 0);
    list.sort((a, b) => (a.distance != null && b.distance != null ? a.distance - b.distance : b.maxFree - a.maxFree));
    return list;
});

function setOrigin(coords: [number, number] | null, label: string | null) {
    origin.value = coords;
    originLabel.value = label;
}

function locate() {
    if (!navigator.geolocation) return;
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        async (p) => {
            locating.value = false;
            const c: [number, number] = [p.coords.latitude, p.coords.longitude];
            setOrigin(c, 'your location');
            try {
                const r = await fetch(`/geocode/reverse?lat=${c[0]}&lng=${c[1]}`).then((x) => x.json());
                if (r.name) originLabel.value = r.name;
            } catch {
                /* keep generic label */
            }
        },
        () => (locating.value = false),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    );
}

async function autoLocate() {
    if (!navigator.geolocation) return;
    try {
        if (navigator.permissions) {
            const s = await navigator.permissions.query({ name: 'geolocation' });
            if (s.state === 'denied') return;
        }
    } catch {
        /* Permissions API unsupported — just try */
    }
    locate();
}

const fmtDate = (iso: string) =>
    new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
const tone = (free: number) =>
    free <= 0 ? 'text-muted-foreground' : free < 5 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400';
const webUrl = (w: string) => (/^https?:\/\//.test(w) ? w : `https://${w}`);

const updated = computed(() =>
    props.payload.updatedAt
        ? new Date(props.payload.updatedAt).toLocaleString(undefined, {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);

onMounted(autoLocate);
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
        <header class="mb-6">
            <h1 class="flex items-center gap-2 text-2xl font-bold tracking-tight sm:text-3xl">
                <Mountain class="size-7 text-primary" />
                Hut beds, last minute
            </h1>
            <p class="mt-1.5 text-sm text-muted-foreground">
                Austrian Alpine huts with free beds in the next {{ payload.days }} days.
                <template v-if="updated"> Updated {{ updated }}.</template>
            </p>
        </header>

        <Card class="mb-5 gap-0 p-4">
            <SearchBox class="mb-3" @select="(r) => setOrigin([r.lat, r.lng], r.name)" />
            <div class="flex flex-wrap items-center gap-2">
                <Button :disabled="locating" class="gap-2" @click="locate">
                    <LocateFixed class="size-4" />
                    {{ locating ? 'Locating…' : 'Use my location' }}
                </Button>
                <span class="text-xs text-muted-foreground">or</span>
                <Button
                    v-for="(_, name) in PRESETS"
                    :key="name"
                    variant="outline"
                    size="sm"
                    :class="originLabel === name && 'border-primary'"
                    @click="setOrigin(PRESETS[name], name)"
                >
                    {{ name }}
                </Button>
                <DatePicker v-model="selectedDate" :min="dates[0]" :max="dates[dates.length - 1]" class="ml-auto" />
            </div>
            <p class="mt-3 flex items-center gap-1 text-xs text-muted-foreground">
                <MapPin class="size-3.5" :class="originLabel ? 'text-primary' : 'opacity-50'" />
                <template v-if="originLabel">
                    Near <strong class="text-foreground">{{ originLabel }}</strong> — sorted by distance.
                </template>
                <template v-else>
                    {{ locating ? 'Finding your location…' : 'Search or use your location to sort by distance.' }}
                </template>
            </p>
        </Card>

        <HutMap :huts="huts" :origin="origin" :days="payload.days" class="mb-4" />

        <p class="mb-3 text-sm text-muted-foreground">
            {{ huts.length }} hut{{ huts.length === 1 ? '' : 's' }} with free beds<template v-if="selectedDate">
                on {{ fmtDate(selectedDate) }}</template
            >.
        </p>

        <div v-if="huts.length === 0" class="rounded-xl border border-dashed p-10 text-center text-muted-foreground">
            No huts with free beds for this selection.
        </div>

        <ul v-else class="flex flex-col gap-3">
            <li v-for="h in huts" :key="h.id">
                <Card class="gap-0 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold">{{ h.name }}</h2>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                <Badge v-if="h.club" variant="secondary" class="font-normal">{{ h.club }}</Badge>
                                <span v-if="h.altitude" class="inline-flex items-center gap-1"><Mountain class="size-3.5" />{{ h.altitude }} m</span>
                                <span v-if="h.distance != null" class="inline-flex items-center gap-1"><MapPin class="size-3.5" />{{ h.distance.toFixed(0) }} km</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Button v-if="h.website" as-child variant="outline" size="sm">
                                <a :href="webUrl(h.website)" target="_blank" rel="noopener">Website <ExternalLink class="size-3.5" /></a>
                            </Button>
                            <Button as-child size="sm">
                                <a :href="h.bookingUrl" target="_blank" rel="noopener">Book <ExternalLink class="size-3.5" /></a>
                            </Button>
                        </div>
                    </div>

                    <div v-if="h.night" class="mt-3 flex items-center gap-2 text-sm">
                        <BedDouble class="size-4" :class="tone(h.night.freeBeds)" />
                        <span class="font-semibold" :class="tone(h.night.freeBeds)">{{ h.night.freeBeds }} free</span>
                        <span class="text-xs text-muted-foreground">
                            on {{ fmtDate(h.night.date) }}<template v-if="h.totalBeds"> · {{ h.totalBeds }} total</template>
                        </span>
                    </div>
                    <div v-else class="mt-3 flex gap-1.5 overflow-x-auto pb-1">
                        <div v-for="n in h.nights" :key="n.date" class="shrink-0 rounded-md border px-2 py-1 text-center" :title="n.percentage ?? ''">
                            <div class="text-[10px] text-muted-foreground">{{ fmtDate(n.date).replace(/,/, '') }}</div>
                            <div class="text-sm font-semibold" :class="tone(n.freeBeds)">{{ n.freeBeds }}</div>
                        </div>
                    </div>
                </Card>
            </li>
        </ul>

        <footer class="mt-10 text-center text-xs text-muted-foreground">
            Data from the Alpenverein / SAC Hut Reservation Service &amp; huetten-holiday.com. Location &amp; map © OpenStreetMap
            contributors. Availability is cached — always confirm on the booking page before travelling.
        </footer>
    </div>
</template>
