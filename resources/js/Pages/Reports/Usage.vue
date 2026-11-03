<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import ReportTabs from '../../Components/ReportTabs.vue'
import { useDates } from '../../composables/useDates'
import { t } from '../../i18n'

defineProps({
    packages: { type: Array, default: () => [] },
    near_limit: { type: Array, default: () => [] },
    never_reported: { type: Number, default: 0 },
    unused: { type: Array, default: () => [] },
})

const { fd } = useDates()
</script>

<template>
    <Head :title="t('reports.title')" />
    <AppLayout :title="t('reports.title')">
        <ReportTabs current="usage" />

        <section class="mb-6">
            <h2 class="mb-2 font-semibold">{{ t('reports.usage.by_package') }}</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="p-3 text-start">{{ t('sub.package') }}</th>
                            <th class="p-3 text-start">{{ t('reports.customers') }}</th>
                            <th class="p-3 text-start">{{ t('reports.usage.reported') }}</th>
                            <th class="p-3 text-start">{{ t('reports.usage.avg_users') }}</th>
                            <th class="p-3 text-start">{{ t('reports.usage.avg_branches') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in packages" :key="p.package" class="border-t">
                            <td class="p-3 font-medium">{{ p.package }}</td>
                            <td class="p-3">{{ p.customers }}</td>
                            <td class="p-3">{{ p.reported }}</td>
                            <td class="p-3">{{ p.avg_users ?? '—' }}</td>
                            <td class="p-3">{{ p.avg_branches ?? '—' }}</td>
                        </tr>
                        <tr v-if="!packages.length"><td colspan="5" class="p-6 text-center text-slate-500">{{ t('common.no_results') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <p v-if="never_reported" class="mt-2 text-xs text-amber-700">{{ t('reports.usage.never_reported', { n: never_reported }) }}</p>
            <p v-if="unused.length" class="mt-1 text-xs text-slate-500">{{ t('reports.usage.unused') }}: {{ unused.join('، ') }}</p>
        </section>

        <section>
            <h2 class="mb-1 font-semibold">{{ t('reports.usage.near') }}</h2>
            <p class="mb-2 text-xs text-slate-500">{{ t('reports.usage.near_hint') }}</p>
            <p v-if="!near_limit.length" class="text-sm text-slate-500">{{ t('reports.usage.nobody') }}</p>
            <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="n in near_limit" :key="n.customer_id" class="border-t first:border-t-0">
                            <td class="p-3"><Link :href="`/customers/${n.customer_id}`" class="font-medium text-violet-700 hover:underline">{{ n.customer }}</Link></td>
                            <td class="p-3 text-slate-600">{{ n.package }}</td>
                            <td class="p-3">
                                <span v-for="c in n.close_to" :key="c.what" class="me-4 text-amber-700">{{ t(`sub.${c.what}`) }}: <b>{{ c.used }} / {{ c.limit }}</b></span>
                            </td>
                            <td class="p-3 text-xs text-slate-400" dir="ltr">{{ fd(n.reported_on) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
