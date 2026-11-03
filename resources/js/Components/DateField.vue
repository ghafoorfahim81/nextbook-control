<script setup>
import { computed } from 'vue'
import DatePicker from 'vue3-persian-datetime-picker'
import { i18n } from '../i18n'
import { usesJalali } from '../utils/dates'

/**
 * A date the person picks. What it holds (v-model) is always Gregorian ISO,
 * 2026-11-03, which is what the server expects; for Persian and Pashto it is
 * shown and picked as Jalali, for English it is the browser's own date input.
 */
const props = defineProps({ modelValue: { type: String, default: '' }, clearable: Boolean })
const emit = defineEmits(['update:modelValue'])

const jalali = usesJalali(i18n.locale)
const model = computed({
    get: () => props.modelValue || '',
    set: (value) => emit('update:modelValue', value || ''),
})

const inputClass = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
</script>

<template>
    <DatePicker
        v-if="jalali"
        v-model="model"
        format="YYYY-MM-DD"
        display-format="jYYYY/jMM/jDD"
        locale="fa"
        :convert-numbers="true"
        :auto-submit="true"
        :editable="false"
        :clearable="clearable"
        :input-attrs="{ class: inputClass, dir: 'ltr' }"
    />
    <input v-else v-model="model" type="date" :class="inputClass" dir="ltr" />
</template>
