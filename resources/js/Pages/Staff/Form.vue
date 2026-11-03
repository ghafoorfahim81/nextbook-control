<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { t } from '../../i18n'

const props = defineProps({
    member: { type: Object, default: null },
    roles: { type: Array, default: () => [] },
})

const m = props.member
const form = useForm({
    name: m?.name ?? '',
    email: m?.email ?? '',
    role: m?.role ?? 'sales',
    is_active: m?.is_active ?? true,
})

const submit = () => (m ? form.put(`/staff/${m.id}`) : form.post('/staff'))
const title = m ? t('staff.edit') : t('staff.new')
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
</script>

<template>
    <Head :title="title" />
    <AppLayout :title="title">
        <form class="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-5" @submit.prevent="submit">
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('common.name') }} *</span>
                <input v-model="form.name" :class="input" /><span v-if="form.errors.name" class="text-red-600">{{ form.errors.name }}</span></label>
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('auth.email') }} *</span>
                <input v-model="form.email" type="email" :disabled="!!m" :class="[input, m ? 'bg-slate-100' : '']" dir="ltr" /><span v-if="form.errors.email" class="text-red-600">{{ form.errors.email }}</span></label>
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('staff.role') }} *</span>
                <select v-model="form.role" :class="input"><option v-for="r in roles" :key="r" :value="r">{{ t(`staff.roles.${r}`) }}</option></select>
                <span class="mt-1 block text-xs text-slate-500">{{ t(`staff.role_hint.${form.role}`) }}</span>
                <span v-if="form.errors.role" class="text-red-600">{{ form.errors.role }}</span></label>
            <label v-if="m" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" :disabled="m.is_me" /> {{ t('staff.active') }}</label>
            <p v-if="!m" class="text-xs text-slate-500">{{ t('staff.password_note') }}</p>

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">{{ t('common.save') }}</button>
                <Link href="/staff" class="rounded-md border border-slate-300 px-4 py-2 text-sm">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
