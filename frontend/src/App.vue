<template>
  <div>
    <Navbar />
    <main>
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { RouterView } from 'vue-router'
import Navbar from '@/components/Navbar.vue'
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

html, body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

body {
  background-color: #f4f6f8;
  color: #1f2933;
}

main {
  min-height: 100vh;
}

button, input, select, textarea {
  font-family: inherit;
}

a {
  color: inherit;
}
</style>
