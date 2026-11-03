<script setup>
import { onBeforeUnmount, onMounted } from 'vue'

defineProps({ title: String, wide: Boolean })
const emit = defineEmits(['close'])

const onKey = (e) => e.key === 'Escape' && emit('close')
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 pt-16" @mousedown.self="emit('close')">
        <div class="w-full rounded-xl bg-white p-5 shadow-xl" :class="wide ? 'max-w-2xl' : 'max-w-md'" role="dialog" aria-modal="true">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ title }}</h2>
                <button type="button" class="text-slate-400 hover:text-slate-700" aria-label="close" @click="emit('close')">✕</button>
            </div>
            <slot />
        </div>
    </div>
</template>
