<script setup>
import { Form } from '@inertiajs/vue3'
</script>

<template>
    <Form action="/department/store" method="post">
        <input type="text" name="name" />
        <input type="text" name="quantity_doctors" />
        <button type="submit">Submit</button>
    </Form>
</template>
