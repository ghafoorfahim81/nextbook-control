<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import { t } from '../../i18n'

const props = defineProps({
    customer: Object,
    subscription: { type: Object, default: null },
    instance: { type: Object, default: null },
    contacts: { type: Array, default: () => [] },
})

const canManage = usePage().props.auth.user.abilities.includes('customers.manage')
const name = props.customer.name_fa || props.customer.name_en

const rows = [
    ['customers.name_en', props.customer.name_en],
    ['customers.name_fa', props.customer.name_fa],
    ['customers.name_ps', props.customer.name_ps],
    ['customers.business_type', props.customer.business_type],
    ['customers.province', props.customer.province],
    ['customers.city', props.customer.city],
    ['customers.license_no', props.customer.license_no],
    ['customers.base_currency', props.customer.base_currency],
]
</script>

<template>
    <Head :title="name" />
    <AppLayout>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <Link href="/customers" class="text-sm text-violet-700 hover:underline">← {{ t('customers.title') }}</Link>
            <h1 class="text-xl font-bold">{{ name }}</h1>
            <StatusBadge kind="status" :value="customer.status" />
            <Link v-if="canManage" :href="`/customers/${customer.id}/edit`" class="ms-auto rounded-md border border-slate-300 px-3 py-1.5 text-sm">{{ t('common.edit') }}</Link>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                    <template v-for="[label, value] in rows" :key="label">
                        <dt class="text-slate-500">{{ t(label) }}</dt>
                        <dd>{{ value || t('common.none') }}</dd>
                    </template>
                </dl>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="mb-2 font-semibold">{{ t('customers.subscription') }}</h2>
                <p v-if="!subscription" class="text-sm text-slate-500">{{ t('customers.no_subscription') }}</p>
                <dl v-else class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt class="text-slate-500">{{ t('customers.package') }}</dt><dd>{{ subscription.package }}</dd>
                    <dt class="text-slate-500">{{ t('common.status') }}</dt><dd><StatusBadge kind="state" :value="subscription.state" /></dd>
                    <dt class="text-slate-500">{{ t('customers.ends_on') }}</dt><dd dir="ltr" class="text-start">{{ subscription.ends_on }}</dd>
                    <dt class="text-slate-500">max users</dt><dd>{{ subscription.limits.max_users ?? '∞' }}</dd>
                    <dt class="text-slate-500">max branches</dt><dd>{{ subscription.limits.max_branches ?? '∞' }}</dd>
                </dl>
            </section>
        </div>
    </AppLayout>
</template>
