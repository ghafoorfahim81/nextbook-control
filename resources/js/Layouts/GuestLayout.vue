<script setup>
defineProps({ title: String })
</script>

<template>
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-5 text-center text-lg font-bold">NextBook <span class="text-violet-600">Control</span></div>
            <h1 v-if="title" class="mb-4 text-base font-semibold">{{ title }}</h1>
            <slot />
        </div>
    </div>
</template>
