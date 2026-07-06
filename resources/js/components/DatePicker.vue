<script setup lang="ts">
import { computed, ref } from 'vue';
import { CalendarDate, type DateValue } from '@internationalized/date';
import { CalendarDays } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

const props = defineProps<{ modelValue: string | null; min: string; max: string }>();
const emit = defineEmits<{ 'update:modelValue': [string | null] }>();

const open = ref(false);

function toDate(iso: string): CalendarDate {
    const [y, m, d] = iso.split('-').map(Number);
    return new CalendarDate(y, m, d);
}
function toIso(dv: DateValue): string {
    return `${dv.year}-${String(dv.month).padStart(2, '0')}-${String(dv.day).padStart(2, '0')}`;
}

const value = computed<DateValue | undefined>({
    get: () => (props.modelValue ? toDate(props.modelValue) : undefined),
    set: (v) => emit('update:modelValue', v ? toIso(v) : null),
});

const minValue = computed(() => toDate(props.min));
const maxValue = computed(() => toDate(props.max));

const label = computed(() =>
    props.modelValue
        ? new Date(props.modelValue + 'T00:00:00').toLocaleDateString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
          })
        : 'Any night',
);

function clear() {
    emit('update:modelValue', null);
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button variant="outline" class="justify-start gap-2 font-normal" :class="!modelValue && 'text-muted-foreground'">
                <CalendarDays class="size-4 opacity-70" />
                {{ label }}
            </Button>
        </PopoverTrigger>
        <PopoverContent class="z-[1000] w-auto p-0" align="end">
            <Calendar
                v-model="value"
                :min-value="minValue"
                :max-value="maxValue"
                :default-placeholder="minValue"
                initial-focus
                @update:model-value="open = false"
            />
            <div class="border-t p-2">
                <Button variant="ghost" size="sm" class="w-full" @click="clear">Any night</Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
