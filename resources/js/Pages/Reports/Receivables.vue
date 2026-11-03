<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import ReportTabs from '../../Components/ReportTabs.vue'
import { t } from '../../i18n'
import { money } from '../../utils/money'

defineProps({
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    buckets: { type: Array, default: () => [] },
})

const tone = { current: 'text-slate-700', d30: 'text-amber-700', d60: 'text-orange-700', d60plus: 'text-red-700 font-semibold' }
</script>

<template>
    <Head :title="t('reports.title')" />
    <AppLayout :title="t('reports.title')">
        <ReportTabs current="receivables" />

        <p v-if="!rows.length" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ t('reports.receivables.none') }}</p>

        <template v-else>
            <div class="mb-6 grid gap-3 sm:grid-cols-2">
                <div v-for="(row, currency) in totals" :key="currency" class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="text-xs text-slate-500">{{ t('reports.receivables.owed') }} ({{ currency }})</div>
                    <div class="mt-1 text-2xl font-bold">{{ money(row.total) }}</div>
                    <div class="mt-2 grid grid-cols-4 gap-2 text-xs">
                        <div v-for="b in buckets" :key="b"><div class="text-slate-500">{{ t(`reports.receivables.${b}`) }}</div><div :class="tone[b]" dir="ltr">{{ money(row[b]) }}</div></div>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="p-3 text-start">{{ t('audit.customer') }}</th>
                            <th v-for="b in buckets" :key="b" class="p-3 text-start">{{ t(`reports.receivables.${b}`) }}</th>
                            <th class="p-3 text-start">{{ t('reports.receivables.total') }}</th>
                            <th class="p-3 text-start">{{ t('reports.receivables.oldest') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.customer_id + r.currency" class="border-t">
                            <td class="p-3"><Link :href="`/customers/${r.customer_id}`" class="text-violet-700 hover:underline">{{ r.customer }}</Link> <span class="text-xs text-slate-400">{{ r.currency }}</span></td>
                            <td v-for="b in buckets" :key="b" class="p-3" :class="Number(r[b]) ? tone[b] : 'text-slate-300'" dir="ltr">{{ Number(r[b]) ? money(r[b]) : '—' }}</td>
                            <td class="p-3 font-semibold" dir="ltr">{{ money(r.total) }}</td>
                            <td class="p-3" :class="r.oldest_days > 0 ? 'text-red-700' : 'text-slate-400'">{{ r.oldest_days > 0 ? t('reports.receivables.days_late', { n: r.oldest_days }) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </AppLayout>
</template>
