<script setup>
import { computed, reactive, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import StatusBadge from './StatusBadge.vue'
import { useAuth } from '../composables/useAuth'
import { t } from '../i18n'

const props = defineProps({
    customerId: String,
    subscription: { type: Object, default: null },
    packages: { type: Array, default: () => [] },
    erpModules: { type: Array, default: () => [] },
    changes: { type: Array, default: () => [] },
    usage: { type: Object, default: null },
})

const { can, canAny } = useAuth()
const open = ref(null) // trial | assign | renew | block | limits | cancel
const base = `/customers/${props.customerId}/subscription`
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
const btn = 'rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50'
const primary = 'rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50'

const sub = computed(() => props.subscription)
const close = () => { open.value = null }

const trial = useForm({ package_id: null, days: 14 })
const assign = useForm({
    package_id: sub.value ? props.packages.find((p) => p.name === sub.value.package)?.id ?? null : null,
    starts_on: sub.value?.starts_on ?? new Date().toISOString().slice(0, 10),
    ends_on: sub.value?.ends_on ?? '',
    notes: '',
})
const renew = useForm({ months: null, invoice: false })
const block = useForm({ reason: '' })
const limits = useForm({
    max_users: sub.value?.limits.max_users ?? null,
    max_branches: sub.value?.limits.max_branches ?? null,
    max_storage_mb: sub.value?.limits.max_storage_mb ?? null,
    max_monthly_documents: sub.value?.limits.max_monthly_documents ?? null,
    modules: [...(sub.value?.limits.modules ?? [])],
    reason: '',
})
const cancel = useForm({ reason: '' })

const send = (form, action) => form.post(`${base}/${action}`, { preserveScroll: true, onSuccess: close })
const unblock = () => useForm({}).post(`${base}/unblock`, { preserveScroll: true })
const toggleModule = (key) => {
    limits.modules = limits.modules.includes(key) ? limits.modules.filter((m) => m !== key) : [...limits.modules, key]
}

const cap = (used, max) => (max ? `${used ?? 0} / ${max}` : `${used ?? 0} (${t('packages.unlimited')})`)
const rows = computed(() => (sub.value ? [
    ['sub.users', cap(props.usage?.users, sub.value.limits.max_users)],
    ['sub.branches', cap(props.usage?.branches, sub.value.limits.max_branches)],
    ['sub.storage', cap(props.usage?.storage_mb, sub.value.limits.max_storage_mb)],
    ['sub.documents', cap(props.usage?.documents_this_month, sub.value.limits.max_monthly_documents)],
] : []))
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 class="me-auto font-semibold">{{ t('sub.title') }}</h2>
            <template v-if="can('subscriptions.assign')">
                <button v-if="!sub" :class="btn" @click="open = 'trial'">{{ t('sub.start_trial') }}</button>
                <button :class="btn" @click="open = 'assign'">{{ sub ? t('sub.change') : t('sub.assign') }}</button>
            </template>
            <button v-if="sub && canAny('subscriptions.assign', 'billing.manage')" :class="btn" @click="open = 'renew'">{{ t('sub.renew') }}</button>
            <template v-if="sub && can('subscriptions.block')">
                <button v-if="sub.state !== 'blocked'" :class="[btn, 'text-red-700']" @click="open = 'block'">{{ t('sub.block') }}</button>
                <button v-else :class="btn" @click="unblock">{{ t('sub.unblock') }}</button>
            </template>
            <button v-if="sub && can('subscriptions.assign')" :class="btn" @click="open = 'limits'">{{ t('sub.limits') }}</button>
        </div>

        <p v-if="!sub" class="text-sm text-slate-500">{{ t('sub.none_yet') }}</p>

        <template v-else>
            <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ t('sub.package') }}</dt>
                <dd>{{ sub.package }} <span class="text-xs text-slate-500">({{ t(`sub.${sub.kind}`) }})</span></dd>
                <dt class="text-slate-500">{{ t('common.status') }}</dt>
                <dd><StatusBadge kind="state" :value="sub.state" /> <span v-if="sub.state === 'expiring'" class="text-xs text-amber-700">{{ t('sub.days_left', { n: sub.days_left }) }}</span></dd>
                <dt class="text-slate-500">{{ t('sub.starts_on') }}</dt><dd dir="ltr" class="text-start">{{ sub.starts_on }}</dd>
                <dt class="text-slate-500">{{ t('sub.ends_on') }}</dt><dd dir="ltr" class="text-start">{{ sub.ends_on }}</dd>
                <dt v-if="sub.block_reason" class="text-slate-500">{{ t('sub.reason') }}</dt><dd v-if="sub.block_reason" class="text-red-700">{{ sub.block_reason }}</dd>
                <template v-for="[label, value] in rows" :key="label"><dt class="text-slate-500">{{ t(label) }}</dt><dd>{{ value }}</dd></template>
                <dt class="text-slate-500">{{ t('sub.modules') }}</dt>
                <dd class="flex flex-wrap gap-1">
                    <span v-for="m in sub.limits.modules" :key="m" class="rounded bg-violet-50 px-1.5 py-0.5 text-xs text-violet-800">{{ t(`modules.${m}`) }}</span>
                    <span v-if="!sub.limits.modules.length" class="text-slate-400">{{ t('common.none') }}</span>
                </dd>
                <dt class="text-slate-500">{{ t('sub.license_version') }}</dt><dd>{{ sub.license_version }}</dd>
            </dl>
            <p v-if="sub.has_overrides" class="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">{{ t('sub.overrides') }}</p>
            <p v-if="usage" class="mt-2 text-xs text-slate-500">{{ t('sub.usage') }}: {{ usage.taken_on }}</p>
            <p v-else class="mt-2 text-xs text-slate-500">{{ t('sub.no_usage') }}</p>

            <div v-if="changes.length" class="mt-4">
                <h3 class="mb-1 text-sm font-medium">{{ t('sub.history') }}</h3>
                <ul class="space-y-0.5 text-xs text-slate-600">
                    <li v-for="(c, i) in changes" :key="i" class="flex gap-2">
                        <span dir="ltr">{{ String(c.created_at).slice(0, 10) }}</span>
                        <span class="font-medium">{{ t(`change.${c.type}`) }}</span>
                        <span v-if="c.reason" class="text-slate-500">— {{ c.reason }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="can('subscriptions.assign')" class="mt-4 border-t pt-3">
                <button class="text-xs text-red-700 hover:underline" @click="open = 'cancel'">{{ t('sub.cancel') }}</button>
            </div>
        </template>

        <!-- Trial -->
        <Modal v-if="open === 'trial'" :title="t('sub.start_trial')" @close="close">
            <form class="space-y-3" @submit.prevent="send(trial, 'trial')">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.pick_package') }}</span>
                    <select v-model="trial.package_id" :class="input"><option :value="null">—</option><option v-for="p in packages" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                    <span v-if="trial.errors.package_id" class="text-red-600">{{ trial.errors.package_id }}</span></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.trial_days') }}</span><input v-model="trial.days" type="number" min="1" max="90" :class="input" dir="ltr" /></label>
                <button type="submit" :disabled="trial.processing" :class="primary">{{ t('common.save') }}</button>
            </form>
        </Modal>

        <!-- Assign / change -->
        <Modal v-if="open === 'assign'" :title="sub ? t('sub.change') : t('sub.assign')" @close="close">
            <form class="space-y-3" @submit.prevent="send(assign, 'assign')">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.pick_package') }}</span>
                    <select v-model="assign.package_id" :class="input"><option :value="null">—</option><option v-for="p in packages" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                    <span v-if="assign.errors.package_id" class="text-red-600">{{ assign.errors.package_id }}</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.starts_on') }}</span><input v-model="assign.starts_on" type="date" :class="input" dir="ltr" /></label>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.ends_on') }}</span><input v-model="assign.ends_on" type="date" :class="input" dir="ltr" /></label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.notes') }}</span><input v-model="assign.notes" :class="input" /></label>
                <button type="submit" :disabled="assign.processing" :class="primary">{{ t('common.save') }}</button>
            </form>
        </Modal>

        <!-- Renew -->
        <Modal v-if="open === 'renew'" :title="t('sub.renew')" @close="close">
            <form class="space-y-3" @submit.prevent="send(renew, 'renew')">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.renew_months') }}</span><input v-model="renew.months" type="number" min="1" max="60" :class="input" dir="ltr" /></label>
                <label v-if="can('billing.manage')" class="flex items-center gap-2 text-sm"><input v-model="renew.invoice" type="checkbox" /> {{ t('sub.renew_invoice') }}</label>
                <button type="submit" :disabled="renew.processing" :class="primary">{{ t('sub.renew') }}</button>
            </form>
        </Modal>

        <!-- Block -->
        <Modal v-if="open === 'block'" :title="t('sub.block')" @close="close">
            <form class="space-y-3" @submit.prevent="send(block, 'block')">
                <p class="text-sm text-slate-600">{{ t('sub.block_hint') }}</p>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.block_reason') }}</span><input v-model="block.reason" :class="input" /></label>
                <button type="submit" :disabled="block.processing" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">{{ t('sub.block') }}</button>
            </form>
        </Modal>

        <!-- Limits -->
        <Modal v-if="open === 'limits'" :title="t('sub.limits')" wide @close="close">
            <form class="space-y-3" @submit.prevent="send(limits, 'override')">
                <p class="text-sm text-slate-600">{{ t('sub.limits_hint') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label v-for="key in ['max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents']" :key="key" class="block text-sm">
                        <span class="mb-1 block text-slate-600">{{ t(`packages.${key}`) }}</span>
                        <input v-model="limits[key]" type="number" min="1" :placeholder="t('packages.unlimited_hint')" :class="input" dir="ltr" />
                        <span v-if="limits.errors[key]" class="text-red-600">{{ limits.errors[key] }}</span>
                    </label>
                </div>
                <div class="flex flex-wrap gap-4">
                    <label v-for="key in erpModules" :key="key" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :checked="limits.modules.includes(key)" @change="toggleModule(key)" /> {{ t(`modules.${key}`) }}
                    </label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.reason') }} *</span><input v-model="limits.reason" :class="input" />
                    <span v-if="limits.errors.reason" class="text-red-600">{{ limits.errors.reason }}</span></label>
                <button type="submit" :disabled="limits.processing" :class="primary">{{ t('common.save') }}</button>
            </form>
        </Modal>

        <!-- Cancel -->
        <Modal v-if="open === 'cancel'" :title="t('sub.cancel')" @close="close">
            <form class="space-y-3" @submit.prevent="send(cancel, 'cancel')">
                <p class="text-sm text-slate-600">{{ t('sub.cancel_hint') }}</p>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('sub.reason') }}</span><input v-model="cancel.reason" :class="input" /></label>
                <button type="submit" :disabled="cancel.processing" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">{{ t('sub.cancel') }}</button>
            </form>
        </Modal>
    </section>
</template>
