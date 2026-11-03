<script setup>
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import DateField from '../../Components/DateField.vue'
import { useDates } from '../../composables/useDates'
import { t } from '../../i18n'

const { fdt } = useDates()

const props = defineProps({
    logs: Object,
    filters: { type: Object, default: () => ({}) },
    groups: { type: Array, default: () => [] },
})

const filter = ref({ ...props.filters })
const expanded = ref(new Set())

let timer = null
const apply = () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filter.value).filter(([, v]) => v))
        router.get('/audit', query, { preserveState: true, replace: true })
    }, 300)
}

const toggle = (id) => {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}
const hasDetail = (log) => !!(log.before || log.after)
const pretty = (value) => JSON.stringify(value, null, 2)
const input = 'rounded-md border border-slate-300 px-3 py-2 text-sm'
</script>

<template>
    <Head :title="t('audit.title')" />
    <AppLayout :title="t('audit.title')">
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <label class="text-sm"><span class="mb-1 block text-xs text-slate-500">{{ t('audit.what') }}</span>
                <select v-model="filter.action" :class="input" @change="apply"><option value="">{{ t('common.all') }}</option><option v-for="g in groups" :key="g" :value="g">{{ g }}</option></select></label>
            <label class="text-sm"><span class="mb-1 block text-xs text-slate-500">{{ t('audit.customer') }}</span>
                <input v-model="filter.customer" :class="input" @input="apply" /></label>
            <div class="text-sm"><span class="mb-1 block text-xs text-slate-500">{{ t('audit.from') }}</span>
                <DateField v-model="filter.from" clearable @update:model-value="apply" /></div>
            <div class="text-sm"><span class="mb-1 block text-xs text-slate-500">{{ t('audit.to') }}</span>
                <DateField v-model="filter.to" clearable @update:model-value="apply" /></div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="p-3 text-start">{{ t('audit.when') }}</th>
                        <th class="p-3 text-start">{{ t('audit.who') }}</th>
                        <th class="p-3 text-start">{{ t('audit.what') }}</th>
                        <th class="p-3 text-start">{{ t('audit.customer') }}</th>
                        <th class="p-3 text-start">{{ t('audit.reason') }}</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="log in logs.data" :key="log.id">
                        <tr class="border-t align-top">
                            <td class="whitespace-nowrap p-3" dir="ltr">{{ fdt(log.at) }}</td>
                            <td class="p-3">{{ log.staff ?? t('common.none') }}</td>
                            <td class="p-3"><span dir="ltr" class="font-mono text-xs">{{ log.action }}</span></td>
                            <td class="p-3"><Link v-if="log.customer_id" :href="`/customers/${log.customer_id}`" class="text-violet-700 hover:underline">{{ log.customer }}</Link><span v-else>{{ t('common.none') }}</span></td>
                            <td class="p-3 text-slate-600">{{ log.reason ?? '' }}</td>
                            <td class="p-3 text-end"><button v-if="hasDetail(log)" class="text-xs text-violet-700 hover:underline" @click="toggle(log.id)">{{ expanded.has(log.id) ? t('audit.hide') : t('audit.details') }}</button></td>
                        </tr>
                        <tr v-if="expanded.has(log.id)" class="bg-slate-50">
                            <td colspan="6" class="p-3">
                                <div class="grid gap-3 sm:grid-cols-2" dir="ltr">
                                    <div v-if="log.before"><div class="mb-1 text-xs font-semibold text-slate-500">{{ t('audit.before') }}</div><pre class="overflow-x-auto rounded bg-white p-2 text-xs">{{ pretty(log.before) }}</pre></div>
                                    <div v-if="log.after"><div class="mb-1 text-xs font-semibold text-slate-500">{{ t('audit.after') }}</div><pre class="overflow-x-auto rounded bg-white p-2 text-xs">{{ pretty(log.after) }}</pre></div>
                                </div>
                                <div v-if="log.ip" class="mt-1 text-xs text-slate-400" dir="ltr">{{ log.ip }}</div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!logs.data.length"><td colspan="6" class="p-6 text-center text-slate-500">{{ t('common.no_results') }}</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="logs.last_page > 1" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="(link, i) in logs.links" :key="i" :href="link.url ?? ''" preserve-state class="rounded border px-3 py-1 text-sm"
                :class="[link.active ? 'bg-violet-600 text-white' : 'bg-white', link.url ? '' : 'pointer-events-none opacity-40']" v-html="link.label" />
        </div>
    </AppLayout>
</template>
