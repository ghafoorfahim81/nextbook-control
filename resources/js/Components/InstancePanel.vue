<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useAuth } from '../composables/useAuth'
import { t } from '../i18n'

const props = defineProps({
    customerId: String,
    instance: { type: Object, default: null },
    issuedToken: { type: String, default: null },
})

const { can } = useAuth()
const copied = ref(false)

const form = useForm({
    erp_company_id: props.instance?.erp_company_id ?? '',
    base_url: props.instance?.base_url ?? '',
})
const save = () => form.put(`/customers/${props.customerId}/instance`, { preserveScroll: true })

const issue = () => {
    if (props.instance?.has_token && !window.confirm(t('instance.reissue_confirm'))) return
    router.post(`/customers/${props.customerId}/instance/token`, {}, { preserveScroll: true })
}

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.issuedToken)
        copied.value = true
    } catch {
        // The token is selectable text; copying by hand still works.
    }
}

const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
const when = (value) => (value ? String(value).replace('T', ' ').slice(0, 16) : t('instance.never'))
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-3 font-semibold">{{ t('instance.title') }}</h2>

        <div v-if="issuedToken" class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
            <p class="mb-1 font-medium text-amber-900">{{ t('instance.issued') }}</p>
            <code dir="ltr" class="block select-all break-all rounded bg-white px-2 py-1">{{ issuedToken }}</code>
            <button type="button" class="mt-2 text-xs text-violet-700 hover:underline" @click="copy">{{ copied ? '✓' : 'Copy' }}</button>
        </div>

        <form v-if="can('customers.manage')" class="space-y-3" @submit.prevent="save">
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('instance.company_id') }}</span>
                <input v-model="form.erp_company_id" maxlength="26" :class="input" dir="ltr" /><span v-if="form.errors.erp_company_id" class="text-red-600">{{ form.errors.erp_company_id }}</span></label>
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('instance.base_url') }}</span>
                <input v-model="form.base_url" :class="input" dir="ltr" placeholder="https://" /><span v-if="form.errors.base_url" class="text-red-600">{{ form.errors.base_url }}</span></label>
            <button type="submit" :disabled="form.processing" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">{{ t('instance.save') }}</button>
        </form>
        <dl v-else class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-slate-500">{{ t('instance.company_id') }}</dt><dd dir="ltr" class="text-start">{{ instance?.erp_company_id || t('common.none') }}</dd>
            <dt class="text-slate-500">{{ t('instance.base_url') }}</dt><dd dir="ltr" class="text-start">{{ instance?.base_url || t('common.none') }}</dd>
        </dl>

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-slate-500">{{ t('instance.version') }}</dt><dd>{{ instance?.erp_version || t('common.none') }}</dd>
            <dt class="text-slate-500">{{ t('instance.last_pull') }}</dt><dd dir="ltr" class="text-start">{{ when(instance?.last_license_pull_at) }}</dd>
            <dt class="text-slate-500">{{ t('instance.last_usage') }}</dt><dd dir="ltr" class="text-start">{{ when(instance?.last_usage_at) }}</dd>
            <dt class="text-slate-500">{{ t('instance.token') }}</dt><dd>{{ instance?.has_token ? t('instance.has_token') : t('instance.no_token') }}</dd>
        </dl>

        <div v-if="can('customers.manage')" class="mt-3">
            <p class="mb-2 text-xs text-slate-500">{{ t('instance.token_hint') }}</p>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" @click="issue">
                {{ instance?.has_token ? t('instance.reissue') : t('instance.issue') }}
            </button>
        </div>
    </section>
</template>
