<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { appStore, configStore, userStore } from '@/stores'
import { handleThemeStyle } from '@/utils/theme'
import { ApiGetSet } from '@/api/comment'

const startup = ref<'loading' | 'ready' | 'error'>('loading')
async function loadConfig() {
  startup.value = 'loading'
  try {
    configStore.refresh(await ApiGetSet())
    userStore.dialogVisible = true
    startup.value = 'ready'
  } catch {
    startup.value = 'error'
  }
}

onMounted(() => {
  loadConfig()
  // 初始化主题
  nextTick(() => {
    handleThemeStyle(appStore.theme.primaryColor)
  })
})
</script>

<template>
  <RouterView v-if="startup === 'ready'" />
  <main v-else class="startup-state" aria-live="polite">
    <p v-if="startup === 'loading'">正在加载系统配置…</p>
    <template v-else>
      <h1>暂时无法加载系统配置</h1>
      <p>请检查网络连接，然后重新加载。</p>
      <el-button type="primary" @click="loadConfig">重新加载</el-button>
    </template>
  </main>
</template>

<style >
.startup-state { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px; padding: 24px; }
.startup-state h1 { font-size: 22px; }
.notify img {
  width: 100% !important;
}

</style>
