<script setup>
import { computed } from 'vue'
import { t } from '../i18n'

const props = defineProps({
    kind: { type: String, default: 'status' }, // status (customer) | state (subscription)
    value: String,
})

const tones = {
    active: 'bg-emerald-100 text-emerald-800',
    trial: 'bg-sky-100 text-sky-800',
    lead: 'bg-slate-100 text-slate-700',
    expiring: 'bg-amber-100 text-amber-800',
    expired: 'bg-red-100 text-red-800',
    blocked: 'bg-red-100 text-red-800',
    cancelled: 'bg-slate-200 text-slate-700',
    archived: 'bg-slate-200 text-slate-600',
    none: 'bg-slate-100 text-slate-500',
}
const tone = computed(() => tones[props.value] ?? tones.none)
</script>

<template>
    <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold" :class="tone">{{ t(`${kind}.${value}`) }}</span>
</template>
