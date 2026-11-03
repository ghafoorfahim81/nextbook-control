<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { useAuth } from '../../composables/useAuth'
import { t } from '../../i18n'

defineProps({ packages: { type: Array, default: () => [] } })

const { can } = useAuth()
const limit = (value) => value ?? t('packages.unlimited')
</script>

<template>
    <Head :title="t('packages.title')" />
    <AppLayout :title="t('packages.title')">
        <div v-if="can('packages.manage')" class="mb-4">
            <Link href="/packages/create" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">{{ t('packages.new') }}</Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="p-3 text-start">{{ t('common.name') }}</th>
                        <th class="p-3 text-start">{{ t('packages.price') }}</th>
                        <th class="p-3 text-start">{{ t('packages.max_users') }}</th>
                        <th class="p-3 text-start">{{ t('packages.max_branches') }}</th>
                        <th class="p-3 text-start">{{ t('packages.modules') }}</th>
                        <th class="p-3 text-start">{{ t('packages.customers') }}</th>
                        <th class="p-3 text-start">{{ t('common.status') }}</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in packages" :key="p.id" class="border-t">
                        <td class="p-3"><div class="font-medium">{{ p.name }}</div><div class="text-xs text-slate-500" dir="ltr">{{ p.code }}</div></td>
                        <td class="p-3 whitespace-nowrap">
                            <template v-if="p.price !== null">{{ p.price.toLocaleString() }} {{ p.currency }} <span class="text-xs text-slate-500">{{ t('packages.per', { n: p.period_months }) }}</span></template>
                            <template v-else>{{ t('common.none') }}</template>
                        </td>
                        <td class="p-3">{{ limit(p.max_users) }}</td>
                        <td class="p-3">{{ limit(p.max_branches) }}</td>
                        <td class="p-3">
                            <div class="flex max-w-xs flex-wrap gap-1">
                                <span v-for="m in p.modules" :key="m" class="rounded bg-violet-50 px-1.5 py-0.5 text-xs text-violet-800">{{ t(`modules.${m}`) }}</span>
                                <span v-if="!p.modules.length" class="text-slate-400">{{ t('common.none') }}</span>
                            </div>
                        </td>
                        <td class="p-3">{{ p.subscriptions_count }}</td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="p.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'">
                                {{ p.is_active ? t('packages.active') : t('packages.inactive') }}
                            </span>
                        </td>
                        <td class="p-3 text-end"><Link v-if="can('packages.manage')" :href="`/packages/${p.id}/edit`" class="text-violet-700 hover:underline">{{ t('common.edit') }}</Link></td>
                    </tr>
                    <tr v-if="!packages.length"><td colspan="8" class="p-6 text-center text-slate-500">{{ t('common.no_results') }}</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
