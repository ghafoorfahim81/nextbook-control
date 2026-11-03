<script setup>
import { router } from '@inertiajs/vue3'
import { useAuth } from '../composables/useAuth'
import { useDates } from '../composables/useDates'
import { t } from '../i18n'

defineProps({ messages: { type: Array, default: () => [] } })

const { can } = useAuth()
const { fdt } = useDates()

const tone = {
    sent: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
    queued: 'bg-sky-100 text-sky-800',
    skipped: 'bg-slate-200 text-slate-600',
}
const retry = (m) => router.post(`/messages/${m.id}/retry`, {}, { preserveScroll: true })
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">{{ t('messages.title') }}</h2>
        <p v-if="!messages.length" class="text-sm text-slate-500">{{ t('messages.none') }}</p>

        <ul class="divide-y text-sm">
            <li v-for="m in messages" :key="m.id" class="flex flex-wrap items-start gap-x-4 gap-y-1 py-2">
                <span class="w-32 shrink-0 text-xs text-slate-500" dir="ltr">{{ fdt(m.at) }}</span>
                <div class="min-w-0 flex-1">
                    <div>
                        <span class="font-medium">{{ t(`messages.kinds.${m.kind}`) }}</span>
                        <span class="ms-2 text-xs text-slate-500">{{ t(`messages.channels.${m.channel}`) }}<template v-if="m.to"> → <span dir="ltr">{{ m.to }}</span></template></span>
                    </div>
                    <div v-if="m.error" class="text-xs" :class="m.status === 'failed' ? 'text-red-700' : 'text-slate-500'">{{ m.error }}</div>
                </div>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="tone[m.status]">{{ t(`messages.states.${m.status}`) }}</span>
                <button v-if="m.status === 'failed' && can('customers.manage')" class="text-xs text-violet-700 hover:underline" @click="retry(m)">{{ t('messages.retry') }}</button>
            </li>
        </ul>
    </section>
</template>
