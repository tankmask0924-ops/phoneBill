<script setup lang="ts">
import { watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { useSiteStore } from '@/stores/site'

// 浏览器标题：页面名 - 系统名称；系统名称加载完、或者在系统配置里改了之后跟着变
const route = useRoute()
const site = useSiteStore()
watchEffect(() => {
  document.title = route.meta.title ? `${route.meta.title} - ${site.name}` : site.name
})
site.load()
</script>

<template>
  <router-view />
</template>
