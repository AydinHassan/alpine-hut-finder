<script setup lang="ts">
import { ref, watch } from 'vue';
import { onClickOutside } from '@vueuse/core';
import { Search } from 'lucide-vue-next';

interface Match {
    name: string;
    lat: number;
    lng: number;
}

const emit = defineEmits<{ select: [Match] }>();

const root = ref<HTMLElement | null>(null);
const q = ref('');
const results = ref<Match[]>([]);
const open = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(q, (val) => {
    clearTimeout(timer);
    if (val.trim().length < 2) {
        results.value = [];
        open.value = false;
        return;
    }
    timer = setTimeout(async () => {
        try {
            const r = await fetch(`/geocode?q=${encodeURIComponent(val.trim())}`).then((x) => x.json());
            results.value = r;
            open.value = r.length > 0;
        } catch {
            results.value = [];
            open.value = false;
        }
    }, 300);
});

function pick(r: Match) {
    q.value = r.name;
    open.value = false;
    emit('select', r);
}

onClickOutside(root, () => (open.value = false));
</script>

<template>
    <div ref="root" class="relative">
        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <input
            v-model="q"
            type="text"
            autocomplete="off"
            placeholder="Search a town or place (e.g. Sölden, Mayrhofen)…"
            class="h-10 w-full rounded-lg border bg-background pr-3 pl-9 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40"
            @focus="open = results.length > 0"
        />
        <ul
            v-if="open"
            class="absolute z-[1000] mt-1 w-full overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-lg"
        >
            <li v-for="r in results" :key="r.name + r.lat">
                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-accent" @click="pick(r)">
                    {{ r.name }}
                </button>
            </li>
        </ul>
    </div>
</template>
