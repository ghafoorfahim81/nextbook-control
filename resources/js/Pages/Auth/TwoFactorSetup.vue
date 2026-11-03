<script setup>
import { onMounted, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import QRCode from 'qrcode'
import GuestLayout from '../../Layouts/GuestLayout.vue'
import { t } from '../../i18n'

const props = defineProps({
    step: String,
    secret: String,
    otpauthUri: String,
    recoveryCodes: { type: Array, default: () => [] },
})

const qr = ref('')
const form = useForm({ code: '' })

onMounted(async () => {
    if (props.otpauthUri) qr.value = await QRCode.toDataURL(props.otpauthUri, { margin: 1, width: 192 })
})

const confirm = () => form.post('/two-factor-setup')
const done = () => router.visit('/dashboard')
</script>

<template>
    <Head :title="t('twofa.title')" />
    <GuestLayout :title="t('twofa.title')">
        <div v-if="step === 'scan'" class="space-y-4">
            <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-800">{{ t('twofa.required') }}</p>
            <p class="text-sm text-slate-600">{{ t('twofa.scan') }}</p>
            <img v-if="qr" :src="qr" alt="QR" class="mx-auto h-48 w-48" />
            <div class="text-center text-xs text-slate-500">
                {{ t('twofa.key') }}: <code dir="ltr" class="select-all rounded bg-slate-100 px-1 py-0.5 text-slate-800">{{ secret }}</code>
            </div>
            <form class="space-y-3" @submit.prevent="confirm">
                <label class="block text-sm">
                    <span class="mb-1 block text-slate-600">{{ t('twofa.confirm_code') }}</span>
                    <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" class="w-full rounded-md border border-slate-300 px-3 py-2 text-center tracking-widest" />
                    <span v-if="form.errors.code" class="mt-1 block text-red-600">{{ form.errors.code }}</span>
                </label>
                <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-violet-600 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                    {{ t('twofa.enable') }}
                </button>
            </form>
        </div>

        <div v-else class="space-y-4">
            <h2 class="font-semibold">{{ t('twofa.recovery_title') }}</h2>
            <p class="text-sm text-slate-600">{{ t('twofa.recovery_hint') }}</p>
            <ul dir="ltr" class="grid grid-cols-2 gap-2 rounded-md bg-slate-50 p-3 font-mono text-sm">
                <li v-for="code in recoveryCodes" :key="code" class="select-all">{{ code }}</li>
            </ul>
            <button class="w-full rounded-md bg-violet-600 py-2 text-sm font-semibold text-white hover:bg-violet-700" @click="done">{{ t('twofa.done') }}</button>
        </div>
    </GuestLayout>
</template>
