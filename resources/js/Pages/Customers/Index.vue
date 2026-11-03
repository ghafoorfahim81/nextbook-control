<script setup>
import { ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import { t } from '../../i18n'

const props = defineProps({
    customers: Object,
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
})

const page = usePage()
const canManage = page.props.auth.user.abilities.includes('customers.manage')

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

let timer = null
const apply = () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
        router.get('/customers', { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true })
    }, 250)
}
</script>

<template>
    <Head :title="t('customers.title')" />
    <AppLayout :title="t('customers.title')">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input v-model="search" :placeholder="t('customers.search')" class="w-64 rounded-md border border-slate-300 px-3 py-2 text-sm" @input="apply" />
            <select v-model="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm" @change="apply">
                <option value="">{{ t('common.all') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ t(`status.${s}`) }}</option>
            </select>
            <Link v-if="canManage" href="/customers/create" class="ms-auto rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">
                {{ t('customers.new') }}
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="p-3 text-start">{{ t('common.name') }}</th>
                        <th class="p-3 text-start">{{ t('common.status') }}</th>
                        <th class="p-3 text-start">{{ t('customers.package') }}</th>
                        <th class="p-3 text-start">{{ t('customers.subscription') }}</th>
                        <th class="p-3 text-start">{{ t('customers.ends_on') }}</th>
                        <th class="p-3 text-start">{{ t('customers.province') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in customers.data" :key="c.id" class="border-t hover:bg-slate-50">
                        <td class="p-3"><Link :href="`/customers/${c.id}`" class="font-medium text-violet-700 hover:underline">{{ c.name }}</Link></td>
                        <td class="p-3"><StatusBadge kind="status" :value="c.status" /></td>
                        <td class="p-3">{{ c.package ?? t('common.none') }}</td>
                        <td class="p-3"><StatusBadge kind="state" :value="c.state" /></td>
                        <td class="p-3" dir="ltr">{{ c.ends_on ?? t('common.none') }}</td>
                        <td class="p-3">{{ c.province ?? t('common.none') }}</td>
                    </tr>
                    <tr v-if="!customers.data.length">
                        <td colspan="6" class="p-6 text-center text-slate-500">{{ t('common.no_results') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="customers.last_page > 1" class="mt-4 flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in customers.links"
                :key="i"
                :href="link.url ?? ''"
                preserve-state
                class="rounded border px-3 py-1 text-sm"
                :class="[link.active ? 'bg-violet-600 text-white' : 'bg-white', link.url ? '' : 'pointer-events-none opacity-40']"
                v-html="link.label"
            />
        </div>
    </AppLayout>
</template>
