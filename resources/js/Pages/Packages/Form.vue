<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import DateField from '../../Components/DateField.vue'
import { useDates } from '../../composables/useDates'
import { t } from '../../i18n'

const { fd } = useDates()

const props = defineProps({
    package: { type: Object, default: null },
    erpModules: { type: Array, default: () => [] },
})

const p = props.package
const form = useForm({
    code: p?.code ?? '',
    name: p?.name ?? '',
    description: p?.description ?? '',
    modules: [...(p?.modules ?? [])],
    max_users: p?.max_users ?? null,
    max_branches: p?.max_branches ?? null,
    max_storage_mb: p?.max_storage_mb ?? null,
    max_monthly_documents: p?.max_monthly_documents ?? null,
    is_active: p?.is_active ?? true,
    sort: p?.sort ?? 0,
    price: p?.price ?? 0,
    currency: p?.currency ?? 'AFN',
    period_months: p?.period_months ?? 12,
    price_effective_from: '',
})

const toggle = (key) => {
    form.modules = form.modules.includes(key) ? form.modules.filter((m) => m !== key) : [...form.modules, key]
}
const submit = () => (p ? form.put(`/packages/${p.id}`) : form.post('/packages'))
const title = p ? t('packages.edit') : t('packages.new')
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
</script>

<template>
    <Head :title="title" />
    <AppLayout :title="title">
        <form class="max-w-3xl space-y-4 rounded-lg border border-slate-200 bg-white p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('packages.code') }} *</span>
                    <input v-model="form.code" :class="input" dir="ltr" /><span v-if="form.errors.code" class="text-red-600">{{ form.errors.code }}</span></label>
                <label class="block text-sm sm:col-span-2"><span class="mb-1 block text-slate-600">{{ t('common.name') }} *</span>
                    <input v-model="form.name" :class="input" /><span v-if="form.errors.name" class="text-red-600">{{ form.errors.name }}</span></label>
            </div>
            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('packages.description') }}</span>
                <textarea v-model="form.description" rows="2" :class="input" /></label>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('packages.price') }} *</span>
                    <input v-model="form.price" type="number" min="0" step="any" :class="input" dir="ltr" /><span v-if="form.errors.price" class="text-red-600">{{ form.errors.price }}</span></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('packages.currency') }} *</span>
                    <input v-model="form.currency" maxlength="3" :class="input" dir="ltr" /><span v-if="form.errors.currency" class="text-red-600">{{ form.errors.currency }}</span></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('packages.period_months') }} *</span>
                    <input v-model="form.period_months" type="number" min="1" :class="input" dir="ltr" /><span v-if="form.errors.period_months" class="text-red-600">{{ form.errors.period_months }}</span></label>
            </div>
            <p class="text-xs text-slate-500">{{ t('packages.price_hint') }}</p>
            <div v-if="p" class="block max-w-xs text-sm"><span class="mb-1 block text-slate-600">{{ t('packages.price_effective_from') }}</span>
                <DateField v-model="form.price_effective_from" clearable /></div>

            <div class="grid gap-4 sm:grid-cols-4">
                <label v-for="key in ['max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents']" :key="key" class="block text-sm">
                    <span class="mb-1 block text-slate-600">{{ t(`packages.${key}`) }}</span>
                    <input v-model="form[key]" type="number" min="1" :placeholder="t('packages.unlimited_hint')" :class="input" dir="ltr" />
                    <span v-if="form.errors[key]" class="text-red-600">{{ form.errors[key] }}</span>
                </label>
            </div>

            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ t('packages.modules') }}</legend>
                <p class="mb-2 text-xs text-slate-500">{{ t('packages.modules_hint') }}</p>
                <div class="flex flex-wrap gap-4">
                    <label v-for="key in erpModules" :key="key" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :checked="form.modules.includes(key)" @change="toggle(key)" /> {{ t(`modules.${key}`) }}
                    </label>
                </div>
            </fieldset>

            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> {{ t('packages.active') }}</label>

            <div v-if="p?.price_history?.length" class="rounded-md bg-slate-50 p-3 text-sm">
                <div class="mb-1 font-medium">{{ t('packages.price_history') }}</div>
                <ul class="space-y-0.5 text-slate-600">
                    <li v-for="(h, i) in p.price_history" :key="i" dir="ltr" class="text-start">{{ fd(h.effective_from) }} — {{ h.amount.toLocaleString() }} {{ h.currency }} / {{ h.period_months }}</li>
                </ul>
            </div>

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">{{ t('common.save') }}</button>
                <Link href="/packages" class="rounded-md border border-slate-300 px-4 py-2 text-sm">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
