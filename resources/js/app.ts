import { createApp } from 'vue';
import 'leaflet/dist/leaflet.css';
import HutFinder from '@/components/HutFinder.vue';
import type { Payload } from '@/types';

const payload = (window as unknown as { __HUTS__: Payload }).__HUTS__;

createApp(HutFinder, { payload }).mount('#app');
