<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useAuth } from '../composables/useAuth'
import { t } from '../i18n'

const props = defineProps({ current: String })
const { can } = useAuth()
const page = usePage()

// The money reports are for those who may see the books.
const tabs = computed(() => [
    { key: 'revenue', href: '/reports/revenue', show: can('billing.view') },
    { key: 'receivables', href: '/reports/receivables', show: can('billing.view') },
    { key: 'renewals', href: '/reports/renewals', show: true },
    { key: 'usage', href: '/reports/usage', show: true },
].filter((tab) => tab.show))

const exportHref = computed(() => `${page.url.split('?')[0]}?export=csv`)
</script>

<template>
    <div class="mb-4 flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="rounded-md px-3 py-1.5 text-sm"
            :class="tab.key === current ? 'bg-violet-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'"
        >
            {{ t(`reports.tabs.${tab.key}`) }}
        </Link>
        <a :href="exportHref" class="ms-auto rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">⬇ {{ t('reports.export') }}</a>
    </div>
</template>
