<script setup>

import { Head, Link } from '@inertiajs/vue3';


defineProps({ department: Object, user: Object })


</script>


<template>
    <Head title="Welcome">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div>
        Страница отделений
    </div>

    <ul>
        <li v-for="item in department">
            {{ item.id }}
        </li>
        <li v-for="item in department">
            {{ item.quantity_doctors }}
        </li>

        <li v-for="item in user">
            {{ item.standart_hours }}
        </li>

    </ul>


</template>

<style scoped>

</style>
