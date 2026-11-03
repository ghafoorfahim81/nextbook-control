<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { t } from '../../i18n'

const props = defineProps({
    customer: { type: Object, default: null },
    businessTypes: { type: Array, default: () => [] },
})

const form = useForm({
    name_en: props.customer?.name_en ?? '',
    name_fa: props.customer?.name_fa ?? '',
    name_ps: props.customer?.name_ps ?? '',
    business_type: props.customer?.business_type ?? '',
    province: props.customer?.province ?? '',
    city: props.customer?.city ?? '',
    license_no: props.customer?.license_no ?? '',
    base_currency: props.customer?.base_currency ?? 'AFN',
    calendar_type: props.customer?.calendar_type ?? 'jalali',
    locale: props.customer?.locale ?? 'fa',
    notes: props.customer?.notes ?? '',
})

const submit = () => (props.customer ? form.put(`/customers/${props.customer.id}`) : form.post('/customers'))
const title = props.customer ? t('customers.edit') : t('customers.new')
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
</script>

<template>
    <Head :title="title" />
    <AppLayout :title="title">
        <form class="max-w-3xl space-y-4 rounded-lg border border-slate-200 bg-white p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.name_en') }} *</span>
                    <input v-model="form.name_en" :class="input" dir="ltr" /><span v-if="form.errors.name_en" class="text-red-600">{{ form.errors.name_en }}</span></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.name_fa') }}</span>
                    <input v-model="form.name_fa" :class="input" /></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.name_ps') }}</span>
                    <input v-model="form.name_ps" :class="input" /></label>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.business_type') }}</span>
                    <select v-model="form.business_type" :class="input">
                        <option value="">{{ t('common.none') }}</option>
                        <option v-for="b in businessTypes" :key="b" :value="b">{{ b }}</option>
                    </select></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.province') }}</span>
                    <input v-model="form.province" :class="input" /></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.city') }}</span>
                    <input v-model="form.city" :class="input" /></label>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.license_no') }}</span>
                    <input v-model="form.license_no" :class="input" dir="ltr" /></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.base_currency') }}</span>
                    <input v-model="form.base_currency" :class="input" dir="ltr" maxlength="3" /></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.calendar_type') }}</span>
                    <select v-model="form.calendar_type" :class="input"><option value="jalali">Jalali</option><option value="gregorian">Gregorian</option></select></label>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.locale') }}</span>
                    <select v-model="form.locale" :class="input"><option value="fa">دری</option><option value="ps">پښتو</option><option value="en">English</option></select></label>
            </div>

            <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('customers.notes') }}</span>
                <textarea v-model="form.notes" rows="3" :class="input" /></label>

            <div class="flex gap-2">
                <button type="submit" :disabled="form.processing" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">{{ t('common.save') }}</button>
                <Link :href="customer ? `/customers/${customer.id}` : '/customers'" class="rounded-md border border-slate-300 px-4 py-2 text-sm">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
