<script setup>
import { Head } from '@inertiajs/vue3'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { t } from '../../i18n'

defineProps({ forced: Boolean })

const form = useForm({ current_password: '', password: '', password_confirmation: '' })
const submit = () => form.put('/account/password', { onFinish: () => form.reset('current_password', 'password', 'password_confirmation') })
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
</script>

<template>
    <Head :title="t('account.title')" />
    <AppLayout :title="t('account.title')">
        <p v-if="forced" class="mb-4 max-w-xl rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{{ t('account.forced') }}</p>

        <form class="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-5" @submit.prevent="submit">
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('account.current') }}</span>
                <input v-model="form.current_password" type="password" autocomplete="current-password" :class="input" dir="ltr" />
                <span v-if="form.errors.current_password" class="text-red-600">{{ form.errors.current_password }}</span></label>
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('account.new') }}</span>
                <input v-model="form.password" type="password" autocomplete="new-password" :class="input" dir="ltr" />
                <span v-if="form.errors.password" class="text-red-600">{{ form.errors.password }}</span></label>
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('account.confirm') }}</span>
                <input v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="input" dir="ltr" /></label>
            <p class="text-xs text-slate-500">{{ t('account.hint') }}</p>
            <button type="submit" :disabled="form.processing" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">{{ t('common.save') }}</button>
        </form>
    </AppLayout>
</template>
