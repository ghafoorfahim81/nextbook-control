<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import GuestLayout from '../../Layouts/GuestLayout.vue'
import { t } from '../../i18n'

const useRecovery = ref(false)
const form = useForm({ code: '', recovery_code: '' })

const toggle = () => {
    useRecovery.value = !useRecovery.value
    form.reset()
    form.clearErrors()
}
const submit = () => form.post('/two-factor-challenge')
</script>

<template>
    <Head :title="t('auth.challenge_title')" />
    <GuestLayout :title="t('auth.challenge_title')">
        <form class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-slate-600">{{ t('auth.challenge_hint') }}</p>

            <label v-if="!useRecovery" class="block text-sm">
                <span class="mb-1 block text-slate-600">{{ t('auth.code') }}</span>
                <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" autofocus dir="ltr" class="w-full rounded-md border border-slate-300 px-3 py-2 text-center tracking-widest" />
                <span v-if="form.errors.code" class="mt-1 block text-red-600">{{ form.errors.code }}</span>
            </label>
            <label v-else class="block text-sm">
                <span class="mb-1 block text-slate-600">{{ t('auth.recovery') }}</span>
                <input v-model="form.recovery_code" autofocus dir="ltr" class="w-full rounded-md border border-slate-300 px-3 py-2" />
                <span v-if="form.errors.recovery_code" class="mt-1 block text-red-600">{{ form.errors.recovery_code }}</span>
            </label>

            <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-violet-600 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                {{ t('auth.submit') }}
            </button>
            <button type="button" class="block w-full text-center text-xs text-violet-700 hover:underline" @click="toggle">
                {{ useRecovery ? t('auth.use_code') : t('auth.use_recovery') }}
            </button>
        </form>
    </GuestLayout>
</template>
