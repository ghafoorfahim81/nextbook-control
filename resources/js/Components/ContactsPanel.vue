<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import { useAuth } from '../composables/useAuth'
import { t } from '../i18n'

const props = defineProps({
    customerId: String,
    contacts: { type: Array, default: () => [] },
})

const { can } = useAuth()
const open = ref(false)
const editing = ref(null)

const blank = () => ({ name: '', role: 'finance', phone: '', whatsapp: '', email: '', is_primary: false, wants_email: true, wants_whatsapp: true })
const form = useForm(blank())

const openAdd = () => {
    editing.value = null
    form.defaults(blank()).reset()
    form.clearErrors()
    open.value = true
}
const openEdit = (contact) => {
    editing.value = contact
    form.defaults({
        name: contact.name, role: contact.role ?? 'other', phone: contact.phone ?? '', whatsapp: contact.whatsapp ?? '', email: contact.email ?? '',
        is_primary: !!contact.is_primary, wants_email: !!contact.wants_email, wants_whatsapp: !!contact.wants_whatsapp,
    }).reset()
    form.clearErrors()
    open.value = true
}

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false } }
    editing.value ? form.put(`/contacts/${editing.value.id}`, options) : form.post(`/customers/${props.customerId}/contacts`, options)
}
const remove = (contact) => window.confirm(t('contacts.remove_confirm')) && router.delete(`/contacts/${contact.id}`, { preserveScroll: true })

const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
const primary = 'rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50'
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="mb-1 flex items-center">
            <h2 class="me-auto font-semibold">{{ t('contacts.title') }}</h2>
            <button v-if="can('customers.manage')" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" @click="openAdd">{{ t('contacts.add') }}</button>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ t('contacts.hint') }}</p>

        <p v-if="!contacts.length" class="rounded bg-amber-50 px-2 py-1 text-sm text-amber-800">{{ t('contacts.none') }}</p>

        <ul class="divide-y">
            <li v-for="c in contacts" :key="c.id" class="flex flex-wrap items-start gap-x-4 gap-y-1 py-2 text-sm">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">
                        {{ c.name }}
                        <span class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ t(`contacts.roles.${c.role ?? 'other'}`) }}</span>
                        <span v-if="c.is_primary" class="ms-1 rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-800">{{ t('contacts.primary') }}</span>
                    </div>
                    <div class="mt-0.5 flex flex-wrap gap-x-4 text-xs text-slate-600" dir="ltr">
                        <span v-if="c.email" :class="c.wants_email ? '' : 'line-through opacity-50'">✉ {{ c.email }}</span>
                        <span v-if="c.whatsapp || c.phone" :class="c.wants_whatsapp && c.whatsapp_ok ? '' : 'line-through opacity-50'">☎ {{ c.whatsapp || c.phone }}</span>
                    </div>
                    <div v-if="c.wants_whatsapp && !c.whatsapp_ok" class="mt-0.5 text-xs text-amber-700">{{ t('contacts.whatsapp_bad') }}</div>
                </div>
                <div v-if="can('customers.manage')" class="flex gap-3 text-xs">
                    <button class="text-violet-700 hover:underline" @click="openEdit(c)">{{ t('common.edit') }}</button>
                    <button class="text-red-700 hover:underline" @click="remove(c)">✕</button>
                </div>
            </li>
        </ul>

        <Modal v-if="open" :title="editing ? t('contacts.edit') : t('contacts.add')" @close="open = false">
            <form class="space-y-3" @submit.prevent="save">
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('contacts.name') }} *</span>
                        <input v-model="form.name" :class="input" /><span v-if="form.errors.name" class="text-red-600">{{ form.errors.name }}</span></label>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('contacts.role') }}</span>
                        <select v-model="form.role" :class="input"><option v-for="r in ['manager', 'finance', 'technical', 'other']" :key="r" :value="r">{{ t(`contacts.roles.${r}`) }}</option></select></label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('contacts.email') }}</span>
                    <input v-model="form.email" type="email" :class="input" dir="ltr" /><span v-if="form.errors.email" class="text-red-600">{{ form.errors.email }}</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('contacts.phone') }}</span>
                        <input v-model="form.phone" :class="input" dir="ltr" placeholder="0791234567" /><span v-if="form.errors.phone" class="text-red-600">{{ form.errors.phone }}</span></label>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('contacts.whatsapp') }}</span>
                        <input v-model="form.whatsapp" :class="input" dir="ltr" /><span v-if="form.errors.whatsapp" class="text-red-600">{{ form.errors.whatsapp }}</span></label>
                </div>
                <p class="text-xs text-slate-500">{{ t('contacts.whatsapp_hint') }}</p>
                <div class="space-y-1 text-sm">
                    <label class="flex items-center gap-2"><input v-model="form.is_primary" type="checkbox" /> {{ t('contacts.primary') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.wants_email" type="checkbox" /> {{ t('contacts.wants_email') }}</label>
                    <label class="flex items-center gap-2"><input v-model="form.wants_whatsapp" type="checkbox" /> {{ t('contacts.wants_whatsapp') }}</label>
                </div>
                <button type="submit" :disabled="form.processing" :class="primary">{{ t('common.save') }}</button>
            </form>
        </Modal>
    </section>
</template>
