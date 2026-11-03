<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { t } from '../../i18n'

defineProps({
    staff: { type: Array, default: () => [] },
    generatedPassword: { type: String, default: null },
    generatedFor: { type: String, default: null },
})

const resetPassword = (m) => window.confirm(t('staff.reset_password_confirm', { name: m.name })) && router.post(`/staff/${m.id}/password`, {}, { preserveScroll: true })
const resetTwoFactor = (m) => window.confirm(t('staff.reset_two_factor_confirm', { name: m.name })) && router.post(`/staff/${m.id}/two-factor-reset`, {}, { preserveScroll: true })
</script>

<template>
    <Head :title="t('staff.title')" />
    <AppLayout :title="t('staff.title')">
        <div v-if="generatedPassword" class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
            <p class="mb-1 font-medium text-amber-900">{{ t('staff.password_for', { email: generatedFor }) }}</p>
            <code dir="ltr" class="block select-all rounded bg-white px-2 py-1">{{ generatedPassword }}</code>
            <p class="mt-1 text-xs text-amber-800">{{ t('staff.password_once') }}</p>
        </div>

        <div class="mb-4">
            <Link href="/staff/create" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">{{ t('staff.new') }}</Link>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="p-3 text-start">{{ t('common.name') }}</th>
                        <th class="p-3 text-start">{{ t('auth.email') }}</th>
                        <th class="p-3 text-start">{{ t('staff.role') }}</th>
                        <th class="p-3 text-start">{{ t('staff.two_factor') }}</th>
                        <th class="p-3 text-start">{{ t('common.status') }}</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in staff" :key="m.id" class="border-t">
                        <td class="p-3 font-medium">{{ m.name }} <span v-if="m.is_me" class="text-xs text-slate-400">({{ t('staff.you') }})</span></td>
                        <td class="p-3" dir="ltr">{{ m.email }}</td>
                        <td class="p-3">{{ t(`staff.roles.${m.role}`) }}</td>
                        <td class="p-3"><span :class="m.two_factor ? 'text-emerald-700' : 'text-amber-700'">{{ m.two_factor ? t('staff.on') : t('staff.not_yet') }}</span></td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="m.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'">
                                {{ m.is_active ? t('staff.active') : t('staff.off') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap p-3 text-end">
                            <Link :href="`/staff/${m.id}/edit`" class="text-violet-700 hover:underline">{{ t('common.edit') }}</Link>
                            <template v-if="!m.is_me">
                                <button class="ms-3 text-slate-600 hover:underline" @click="resetPassword(m)">{{ t('staff.reset_password') }}</button>
                                <button class="ms-3 text-slate-600 hover:underline" @click="resetTwoFactor(m)">{{ t('staff.reset_two_factor') }}</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
