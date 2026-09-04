<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { inject, onMounted, ref, unref, type Ref } from 'vue'
import { ApiDomainEnableList } from '@/api/domain'
import useForm from '@/hooks/form'
import type { FeedbackChannel, FeedbackChannelForm } from '@/models/feedback'
import {
  FEEDBACK_CATEGORY_MAX,
  FEEDBACK_CATEGORY_MAX_COUNT,
  FEEDBACK_CATEGORY_MIN_COUNT,
  FEEDBACK_DEFAULT_CATEGORIES,
  FEEDBACK_INTRO_MAX,
  FEEDBACK_NAME_MAX,
  FEEDBACK_OPERATOR_MAX,
  FEEDBACK_PHONE_MAX,
  FEEDBACK_RETENTION_DEFAULT,
  FEEDBACK_RETENTION_MAX,
  FEEDBACK_RETENTION_MIN,
  FEEDBACK_SLA_MAX,
  FEEDBACK_WEBHOOK_MAX
} from '@/models/feedback'

const emit = defineEmits<{
  (e: 'clear-webhook', form: FeedbackChannelForm): void
}>()

interface DomainOption {
  id: number
  title: string
}

const formRef = ref<FormInstance>()
const domainList = ref<DomainOption[]>([])
const detail = inject('detail', ref(null)) as Ref<FeedbackChannel | null>

const { formData, formLoading } = useForm<FeedbackChannelForm>({
  formRef,
  defVal(row) {
    return {
      domain_id: row?.domain_id,
      name: row?.name ?? '',
      operator_name: row?.operator_name ?? '',
      intro: row?.intro ?? '',
      service_phone: row?.service_phone ?? '',
      sla_text: row?.sla_text ?? '',
      categories:
        Array.isArray(row?.categories) && row.categories.length > 0
          ? [...row.categories]
          : [...FEEDBACK_DEFAULT_CATEGORIES],
      contact_required: Boolean(row?.contact_required),
      retention_days: row?.retention_days ?? FEEDBACK_RETENTION_DEFAULT,
      webhook_url: ''
    }
  }
})

const validateCategories = (_rule: unknown, value: string[], callback: (error?: Error) => void) => {
  const names = (value || []).map((item) => item.trim()).filter((item) => item.length > 0)
  if (names.length < FEEDBACK_CATEGORY_MIN_COUNT) {
    callback(new Error('至少保留一个问题分类'))
    return
  }
  if (names.length > FEEDBACK_CATEGORY_MAX_COUNT) {
    callback(new Error(`问题分类不超过${FEEDBACK_CATEGORY_MAX_COUNT}个`))
    return
  }
  if (names.some((name) => name.length > FEEDBACK_CATEGORY_MAX)) {
    callback(new Error(`单个分类不超过${FEEDBACK_CATEGORY_MAX}个字`))
    return
  }
  if (new Set(names).size !== names.length) {
    callback(new Error('问题分类不能重复'))
    return
  }
  callback()
}

const rules: FormRules = {
  domain_id: [{ required: true, message: '请选择启用中的域名', trigger: 'change' }],
  name: [
    { required: true, message: '请输入渠道名称', trigger: 'blur' },
    { max: FEEDBACK_NAME_MAX, message: `渠道名称不超过${FEEDBACK_NAME_MAX}个字`, trigger: 'blur' }
  ],
  operator_name: [
    { required: true, message: '请输入运营主体', trigger: 'blur' },
    { max: FEEDBACK_OPERATOR_MAX, message: `运营主体不超过${FEEDBACK_OPERATOR_MAX}个字`, trigger: 'blur' }
  ],
  intro: [{ max: FEEDBACK_INTRO_MAX, message: `简介不超过${FEEDBACK_INTRO_MAX}个字`, trigger: 'blur' }],
  service_phone: [{ max: FEEDBACK_PHONE_MAX, message: `客服电话不超过${FEEDBACK_PHONE_MAX}个字`, trigger: 'blur' }],
  sla_text: [
    { required: true, message: '请输入处理时效说明', trigger: 'blur' },
    { max: FEEDBACK_SLA_MAX, message: `处理时效说明不超过${FEEDBACK_SLA_MAX}个字`, trigger: 'blur' }
  ],
  categories: [{ validator: validateCategories, trigger: 'blur' }],
  contact_required: [{ required: true, message: '请选择是否必须填写联系方式', trigger: 'change' }],
  retention_days: [
    { required: true, message: '请填写保存期限', trigger: 'change' },
    {
      type: 'number',
      min: FEEDBACK_RETENTION_MIN,
      max: FEEDBACK_RETENTION_MAX,
      message: `保存期限为${FEEDBACK_RETENTION_MIN}-${FEEDBACK_RETENTION_MAX}天`,
      trigger: 'change'
    }
  ],
  webhook_url: [{ max: FEEDBACK_WEBHOOK_MAX, message: `机器人地址不超过${FEEDBACK_WEBHOOK_MAX}个字符`, trigger: 'blur' }]
}

const addCategory = () => {
  if (formData.value.categories.length >= FEEDBACK_CATEGORY_MAX_COUNT) {
    return
  }
  formData.value.categories.push('')
}

const removeCategory = (index: number) => {
  if (formData.value.categories.length <= FEEDBACK_CATEGORY_MIN_COUNT) {
    return
  }
  formData.value.categories.splice(index, 1)
}

const clearWebhook = () => {
  if (unref(formLoading)) {
    return
  }
  formRef.value
    ?.validate()
    .then(() => {
      emit('clear-webhook', formData.value)
    })
    .catch(() => undefined)
}

onMounted(async () => {
  domainList.value = (await ApiDomainEnableList()) as DomainOption[]
})
</script>

<template>
  <el-form :model="formData" :rules="rules" ref="formRef" v-loading="formLoading" label-width="140px">
    <el-alert
      v-if="detail?.id && detail.domain_available === false"
      title="当前域名不可用，请改选一个已启用域名后再保存。"
      type="warning"
      :closable="false"
      class="m-b-20"
    />
    <el-form-item prop="name" label="渠道名称">
      <el-input v-model="formData.name" :maxlength="FEEDBACK_NAME_MAX" placeholder="例如：商家售后反馈"></el-input>
    </el-form-item>
    <el-form-item prop="operator_name" label="运营主体">
      <el-input v-model="formData.operator_name" :maxlength="FEEDBACK_OPERATOR_MAX" placeholder="请输入对外展示的运营主体"></el-input>
    </el-form-item>
    <el-form-item prop="domain_id" label="分享域名">
      <el-select v-model="formData.domain_id" placeholder="请选择已启用域名" style="width: 100%">
        <el-option v-for="item in domainList" :key="item.id" :label="item.title" :value="item.id"></el-option>
      </el-select>
    </el-form-item>
    <el-form-item prop="intro" label="简介">
      <el-input
        v-model="formData.intro"
        type="textarea"
        :rows="3"
        :maxlength="FEEDBACK_INTRO_MAX"
        show-word-limit
        placeholder="选填，向客户说明商家售后反馈用途"
      ></el-input>
    </el-form-item>
    <el-form-item prop="service_phone" label="客服电话">
      <el-input v-model="formData.service_phone" :maxlength="FEEDBACK_PHONE_MAX" placeholder="选填"></el-input>
    </el-form-item>
    <el-form-item prop="sla_text" label="处理时效">
      <el-input v-model="formData.sla_text" :maxlength="FEEDBACK_SLA_MAX" placeholder="例如：2小时内响应"></el-input>
    </el-form-item>
    <el-form-item prop="categories" label="问题分类">
      <div class="category-list">
        <div v-for="(_item, index) in formData.categories" :key="index" class="category-row">
          <el-input
            v-model="formData.categories[index]"
            :maxlength="FEEDBACK_CATEGORY_MAX"
            placeholder="分类名称"
          ></el-input>
          <el-button
            v-if="formData.categories.length > FEEDBACK_CATEGORY_MIN_COUNT"
            type="primary"
            link
            @click="removeCategory(index)"
          >
            删除
          </el-button>
        </div>
        <el-button
          v-if="formData.categories.length < FEEDBACK_CATEGORY_MAX_COUNT"
          type="primary"
          plain
          @click="addCategory"
        >
          添加分类
        </el-button>
      </div>
    </el-form-item>
    <el-form-item prop="contact_required" label="联系方式必填">
      <el-switch
        v-model="formData.contact_required"
        :active-value="true"
        :inactive-value="false"
        active-text="必填"
        inactive-text="选填"
        inline-prompt
      ></el-switch>
    </el-form-item>
    <el-form-item prop="retention_days" label="保存期限（天）">
      <el-input-number
        v-model="formData.retention_days"
        :min="FEEDBACK_RETENTION_MIN"
        :max="FEEDBACK_RETENTION_MAX"
        :step="1"
      ></el-input-number>
    </el-form-item>
    <el-form-item prop="webhook_url" label="企业微信机器人">
      <div class="webhook-box">
        <el-input
          v-model="formData.webhook_url"
          :maxlength="FEEDBACK_WEBHOOK_MAX"
          placeholder="留空则保留已保存的机器人地址"
          show-password
        ></el-input>
        <div class="webhook-hint">
          <span v-if="detail?.webhook_configured">已配置机器人。保存时留空不会覆盖原地址。</span>
          <span v-else>未配置。填写后仅用于群通知，保存后不会回显。</span>
          <el-button
            v-if="detail?.id && detail.webhook_configured"
            type="danger"
            link
            :disabled="Boolean(formLoading)"
            @click="clearWebhook"
          >
            清除机器人
          </el-button>
        </div>
      </div>
    </el-form-item>
  </el-form>
</template>

<style scoped lang="scss">
.category-list {
  width: 100%;
}
.category-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.webhook-box {
  width: 100%;
}
.webhook-hint {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-top: 8px;
  color: #666;
  line-height: 1.4;
}
</style>
