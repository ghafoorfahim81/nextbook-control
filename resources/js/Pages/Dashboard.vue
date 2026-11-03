<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import { t } from '../i18n'
import { useDates } from '../composables/useDates'

const { fd } = useDates()

defineProps({
    counts: Object,
    receivable: { type: Array, default: () => [] },
    ending: { type: Array, default: () => [] },
})

const cards = ['customers', 'active', 'trial', 'expiring', 'blocked', 'unpaid']
</script>

<template>
    <Head :title="t('dashboard.title')" />
    <AppLayout :title="t('dashboard.title')">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <div v-for="key in cards" :key="key" class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">{{ t(`dashboard.${key}`) }}</div>
                <div class="mt-1 text-2xl font-bold">{{ counts[key] }}</div>
            </div>
        </div>

        <div v-if="receivable.length" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
            <div class="mb-1 text-xs text-slate-500">{{ t('dashboard.receivable') }}</div>
            <div class="flex flex-wrap gap-6">
                <div v-for="row in receivable" :key="row.currency" class="text-lg font-bold">
                    {{ row.amount.toLocaleString() }} <span class="text-sm font-normal text-slate-500">{{ row.currency }}</span>
                </div>
            </div>
        </div>

        <section class="mt-6">
            <h2 class="mb-2 font-semibold">{{ t('dashboard.expiring_list') }}</h2>
            <p v-if="!ending.length" class="text-sm text-slate-500">{{ t('dashboard.no_expiring') }}</p>
            <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="row in ending" :key="row.customer_id" class="border-t first:border-t-0">
                            <td class="p-3">
                                <Link :href="`/customers/${row.customer_id}`" class="font-medium text-violet-700 hover:underline">{{ row.customer }}</Link>
                            </td>
                            <td class="p-3 text-slate-600">{{ row.package }}</td>
                            <td class="p-3" dir="ltr">{{ fd(row.ends_on) }}</td>
                            <td class="p-3 text-amber-700">{{ row.days_left }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
