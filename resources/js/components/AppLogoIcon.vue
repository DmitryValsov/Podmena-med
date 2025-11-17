<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <img :src="'/img/auth/red-krest.jpg'" :style="{ 'min-width': '70px' }" alt="Мое изображение">
</template>
