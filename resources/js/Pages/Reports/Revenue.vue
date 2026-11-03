<script setup>
import { Head } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import ReportTabs from '../../Components/ReportTabs.vue'
import { i18n, t } from '../../i18n'
import { money } from '../../utils/money'
import { monthLabel } from '../../utils/dates'

const props = defineProps({
    months: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    recurring: { type: Object, required: true },
})

const label = (month) => monthLabel(month, i18n.locale)

// Bars are drawn against the biggest figure of that currency, so a quiet month is visibly quiet.
const peak = (currency) => Math.max(1, ...props.months.flatMap((m) => [Number(m.invoiced[currency] ?? 0), Number(m.collected[currency] ?? 0)]))
const width = (value, currency) => `${Math.round((Number(value ?? 0) / peak(currency)) * 100)}%`
const total = (key, currency) => props.months.reduce((sum, m) => sum + Number(m[key][currency] ?? 0), 0)
</script>

<template>
    <Head :title="t('reports.title')" />
    <AppLayout :title="t('reports.title')">
        <ReportTabs current="revenue" />

        <section class="mb-6">
            <h2 class="mb-1 font-semibold">{{ t('reports.revenue.recurring') }}</h2>
            <p class="mb-3 text-xs text-slate-500">{{ t('reports.revenue.recurring_hint') }}</p>
            <p v-if="!Object.keys(recurring.by_currency).length" class="text-sm text-slate-500">{{ t('reports.revenue.no_recurring') }}</p>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <template v-for="(row, currency) in recurring.by_currency" :key="currency">
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="text-xs text-slate-500">{{ t('reports.revenue.monthly') }} ({{ currency }})</div>
                        <div class="mt-1 text-2xl font-bold">{{ money(row.monthly) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="text-xs text-slate-500">{{ t('reports.revenue.annual') }} ({{ currency }})</div>
                        <div class="mt-1 text-2xl font-bold">{{ money(row.annual) }}</div>
                        <div class="text-xs text-slate-500">{{ row.customers }} {{ t('reports.customers') }}</div>
                    </div>
                </template>
            </div>
            <p v-if="recurring.unpriced" class="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">{{ t('reports.revenue.unpriced', { n: recurring.unpriced }) }}</p>
        </section>

        <section v-for="currency in currencies" :key="currency" class="mb-6">
            <h2 class="mb-2 font-semibold">{{ t('reports.revenue.by_month') }} — {{ currency }}</h2>
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="mb-2 flex gap-4 text-xs text-slate-500">
                    <span><i class="me-1 inline-block size-2 rounded-sm bg-slate-300"></i>{{ t('reports.revenue.invoiced') }}</span>
                    <span><i class="me-1 inline-block size-2 rounded-sm bg-violet-600"></i>{{ t('reports.revenue.collected') }}</span>
                </div>
                <div v-for="month in months" :key="month.key" class="grid grid-cols-[6rem_1fr_9rem] items-center gap-3 py-0.5 text-xs">
                    <span class="text-slate-600">{{ label(month) }}</span>
                    <div class="space-y-0.5">
                        <div class="h-2 rounded-sm bg-slate-300" :style="{ width: width(month.invoiced[currency], currency) }"></div>
                        <div class="h-2 rounded-sm bg-violet-600" :style="{ width: width(month.collected[currency], currency) }"></div>
                    </div>
                    <span class="text-end" dir="ltr">{{ money(month.invoiced[currency]) }} / {{ money(month.collected[currency]) }}</span>
                </div>
                <div class="mt-2 flex justify-end gap-6 border-t pt-2 text-sm font-semibold" dir="ltr">
                    <span>{{ money(total('invoiced', currency)) }}</span><span class="text-violet-700">{{ money(total('collected', currency)) }}</span>
                </div>
            </div>
        </section>

        <p v-if="!currencies.length" class="text-sm text-slate-500">{{ t('reports.revenue.nothing') }}</p>

        <section v-if="recurring.by_province.length" class="mb-6">
            <h2 class="mb-2 font-semibold">{{ t('reports.revenue.by_province') }}</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr><th class="p-3 text-start">{{ t('customers.province') }}</th><th class="p-3 text-start">{{ t('reports.customers') }}</th><th class="p-3 text-start">{{ t('reports.revenue.monthly') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in recurring.by_province" :key="row.province" class="border-t">
                            <td class="p-3">{{ row.province }}</td>
                            <td class="p-3">{{ row.customers }}</td>
                            <td class="p-3" dir="ltr">
                                <span v-for="(amount, currency) in row.monthly" :key="currency" class="me-3">{{ money(amount) }} {{ currency }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
