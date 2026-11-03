<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import ReportTabs from '../../Components/ReportTabs.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import { useDates } from '../../composables/useDates'
import { i18n, t } from '../../i18n'
import { monthLabel } from '../../utils/dates'

defineProps({
    months: { type: Array, default: () => [] },
    lapsed: { type: Array, default: () => [] },
    overall_rate: { type: [Number, null], default: null },
    cancelled: { type: Number, default: 0 },
})

const { fd } = useDates()
const rateTone = (rate) => (rate === null ? 'text-slate-300' : rate >= 80 ? 'text-emerald-700' : rate >= 50 ? 'text-amber-700' : 'text-red-700')
</script>

<template>
    <Head :title="t('reports.title')" />
    <AppLayout :title="t('reports.title')">
        <ReportTabs current="renewals" />

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">{{ t('reports.renewals.overall') }}</div>
                <div class="mt-1 text-2xl font-bold" :class="rateTone(overall_rate)">{{ overall_rate === null ? '—' : `${overall_rate}%` }}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">{{ t('reports.renewals.cancelled') }}</div>
                <div class="mt-1 text-2xl font-bold">{{ cancelled }}</div>
            </div>
        </div>

        <section class="mb-6">
            <h2 class="mb-1 font-semibold">{{ t('reports.renewals.by_month') }}</h2>
            <p class="mb-2 text-xs text-slate-500">{{ t('reports.renewals.hint') }}</p>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="p-3 text-start">{{ t('reports.month') }}</th>
                            <th class="p-3 text-start">{{ t('reports.renewals.ended') }}</th>
                            <th class="p-3 text-start">{{ t('reports.renewals.renewed') }}</th>
                            <th class="p-3 text-start">{{ t('reports.renewals.lapsed') }}</th>
                            <th class="p-3 text-start">{{ t('reports.renewals.rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in months" :key="m.key" class="border-t">
                            <td class="p-3">{{ monthLabel(m, i18n.locale) }}</td>
                            <td class="p-3">{{ m.renewed + m.lapsed }}</td>
                            <td class="p-3 text-emerald-700">{{ m.renewed }}</td>
                            <td class="p-3" :class="m.lapsed ? 'text-red-700' : 'text-slate-300'">{{ m.lapsed }}</td>
                            <td class="p-3 font-semibold" :class="rateTone(m.rate)">{{ m.rate === null ? '—' : `${m.rate}%` }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="mb-1 font-semibold">{{ t('reports.renewals.to_call') }}</h2>
            <p class="mb-2 text-xs text-slate-500">{{ t('reports.renewals.to_call_hint') }}</p>
            <p v-if="!lapsed.length" class="text-sm text-slate-500">{{ t('reports.renewals.nobody') }}</p>
            <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="l in lapsed" :key="l.customer_id" class="border-t first:border-t-0">
                            <td class="p-3"><Link :href="`/customers/${l.customer_id}`" class="font-medium text-violet-700 hover:underline">{{ l.customer }}</Link></td>
                            <td class="p-3 text-slate-600">{{ l.package }}</td>
                            <td class="p-3" dir="ltr">{{ fd(l.ends_on) }}</td>
                            <td class="p-3 text-red-700">{{ t('reports.renewals.days_ago', { n: l.days_ago }) }}</td>
                            <td class="p-3"><StatusBadge kind="state" :value="l.state" /></td>
                            <td class="p-3 text-slate-600" dir="ltr">{{ l.contact ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
