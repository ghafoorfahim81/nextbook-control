<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import GuestLayout from '../../Layouts/GuestLayout.vue'
import { t } from '../../i18n'

const form = useForm({ email: '', password: '' })
const submit = () => form.post('/login', { onFinish: () => form.reset('password') })
</script>

<template>
    <Head :title="t('auth.title')" />
    <GuestLayout :title="t('auth.title')">
        <form class="space-y-4" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="mb-1 block text-slate-600">{{ t('auth.email') }}</span>
                <input v-model="form.email" type="email" autocomplete="username" autofocus dir="ltr" class="w-full rounded-md border border-slate-300 px-3 py-2" />
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-slate-600">{{ t('auth.password') }}</span>
                <input v-model="form.password" type="password" autocomplete="current-password" dir="ltr" class="w-full rounded-md border border-slate-300 px-3 py-2" />
            </label>
            <p v-if="form.errors.email" class="text-sm text-red-600">{{ form.errors.email }}</p>
            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-violet-600 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                {{ t('auth.submit') }}
            </button>
        </form>
    </GuestLayout>
</template>
