<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { ApiChangePassword } from '@/api/user'
import { userStore } from '@/stores'

const router = useRouter()
const form = ref<FormInstance>()
const busy = ref(false)
const data = reactive({ password: '', password_confirmation: '' })
const rules: FormRules = {
  password: [{ required: true, message: '请输入新密码', trigger: 'blur' }],
  password_confirmation: [{
    validator: (_rule, value, callback) => {
      if (!value || value !== data.password) callback(new Error('两次输入的密码必须一致'))
      else callback()
    },
    trigger: 'blur'
  }]
}

async function submit() {
  if (!await form.value?.validate().catch(() => false)) return
  busy.value = true
  try {
    await ApiChangePassword(data)
    ElMessage.success('密码已更新')
    await router.replace('/home')
  } catch {
    // HTTP 边界显示服务端校验结果，保留输入供修正。
  } finally {
    busy.value = false
  }
}

async function logout() {
  await userStore.logout()
  await router.replace('/login')
}
</script>

<template>
  <main class="password-page">
    <el-card class="password-card">
      <h1>首次登录，请修改密码</h1>
      <p>设置你自己的密码后，即可进入后台。</p>
      <el-form ref="form" :model="data" :rules="rules" label-position="top" :disabled="busy" @submit.prevent="submit">
        <el-form-item label="新密码" prop="password">
          <el-input v-model="data.password" type="password" show-password autocomplete="new-password" placeholder="请输入新密码" />
        </el-form-item>
        <el-form-item label="确认新密码" prop="password_confirmation">
          <el-input v-model="data.password_confirmation" type="password" show-password autocomplete="new-password" placeholder="请再次输入新密码" />
        </el-form-item>
        <el-button type="primary" native-type="submit" :loading="busy">保存并进入后台</el-button>
        <el-button @click="logout">退出登录</el-button>
      </el-form>
    </el-card>
  </main>
</template>

<style scoped>
.password-page { min-height: 100vh; display: grid; place-items: center; padding: 20px; background: #f5f7fa; }
.password-card { width: 100%; max-width: 480px; }
h1 { font-size: 22px; margin-bottom: 12px; }
p { color: #606266; margin-bottom: 24px; }
</style>
