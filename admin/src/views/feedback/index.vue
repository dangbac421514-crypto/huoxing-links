<script setup lang="ts">
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { onMounted, ref } from 'vue'
import FormModal from '@/components/FormModal.vue'
import {
  ApiCreateFeedbackChannel,
  ApiFeedbackChannelDetail,
  ApiFeedbackChannelStatus,
  ApiFeedbackChannelTestNotification,
  ApiFeedbackChannels,
  ApiUpdateFeedbackChannel
} from '@/api/feedback'
import type { FeedbackChannel, FeedbackChannelForm, FeedbackChannelPayload } from '@/models/feedback'
import { setClipboard } from '@/utils'
import ChannelForm from '@/views/feedback/components/ChannelForm.vue'

const list = ref<FeedbackChannel[]>([])
const listLoading = ref(false)
const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const pageSizes = [10, 20, 30, 40, 50]
const formVisible = ref(false)
const formLoading = ref(false)
const detailData = ref<FeedbackChannel | null>(null)

const loadList = () => {
  listLoading.value = true
  ApiFeedbackChannels({ page: page.value, page_size: pageSize.value })
    .then((res) => {
      list.value = res.data
      total.value = res.meta.total
    })
    .finally(() => {
      listLoading.value = false
    })
}

const reloadSaved = (id: number) => {
  return ApiFeedbackChannelDetail(id).then((detail) => {
    detailData.value = detail
    loadList()
    return detail
  })
}

const buildPayload = (data: FeedbackChannelForm, mode: 'save' | 'clear'): FeedbackChannelPayload => {
  const payload: FeedbackChannelPayload = {
    domain_id: Number(data.domain_id),
    name: data.name.trim(),
    operator_name: data.operator_name.trim(),
    intro: data.intro.trim(),
    service_phone: data.service_phone.trim() ? data.service_phone.trim() : null,
    sla_text: data.sla_text.trim(),
    categories: data.categories.map((item) => item.trim()).filter((item) => item.length > 0),
    contact_required: Boolean(data.contact_required),
    retention_days: Number(data.retention_days)
  }
  if (mode === 'clear') {
    payload.webhook_url = null
  } else if (data.webhook_url.trim()) {
    payload.webhook_url = data.webhook_url.trim()
  }
  return payload
}

const showCreate = () => {
  detailData.value = null
  formVisible.value = true
}

const showEdit = (row: FeedbackChannel) => {
  formLoading.value = true
  formVisible.value = true
  ApiFeedbackChannelDetail(row.id)
    .then((detail) => {
      detailData.value = detail
    })
    .finally(() => {
      formLoading.value = false
    })
}

const submitChannel = (data: FeedbackChannelForm, detail?: FeedbackChannel | null) => {
  formLoading.value = true
  const payload = buildPayload(data, 'save')
  const request = detail?.id ? ApiUpdateFeedbackChannel(detail.id, payload) : ApiCreateFeedbackChannel(payload)
  request
    .then((saved) => reloadSaved(saved.id))
    .then(() => {
      ElMessage.success('保存成功')
      formVisible.value = false
    })
    .finally(() => {
      formLoading.value = false
    })
}

const clearWebhook = (data: FeedbackChannelForm) => {
  if (!detailData.value?.id) {
    return
  }
  ElMessageBox.confirm('确定清除已保存的企业微信机器人？清除后需重新填写才能发送通知。', '清除机器人', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(() => {
    formLoading.value = true
    ApiUpdateFeedbackChannel(detailData.value!.id, buildPayload(data, 'clear'))
      .then((saved) => reloadSaved(saved.id))
      .then(() => {
        ElMessage.success('已清除机器人')
      })
      .finally(() => {
        formLoading.value = false
      })
  })
}

const copyShareUrl = (row: FeedbackChannel) => {
  ApiFeedbackChannelDetail(row.id).then((detail) => {
    if (!detail.share_url) {
      ElMessage.error('域名不可用，无法复制分享地址')
      return
    }
    setClipboard(detail.share_url)
  })
}

const toggleStatus = (row: FeedbackChannel) => {
  const next = !row.status
  ElMessageBox.confirm(
    next ? '确定启用该客诉受理渠道？' : '确定停用该客诉受理渠道？停用后客户将无法提交商家售后反馈。',
    next ? '启用渠道' : '停用渠道',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }
  ).then(() => {
    ApiFeedbackChannelStatus(row.id, { status: next })
      .then((saved) => reloadSaved(saved.id))
      .then(() => {
        ElMessage.success(next ? '已启用' : '已停用')
      })
  })
}

const testNotification = (row: FeedbackChannel) => {
  ElMessageBox.confirm('将发送一条带“测试”标识的通知，不含客户信息。', '测试通知', {
    confirmButtonText: '发送',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(() => {
    ApiFeedbackChannelTestNotification(row.id).then(() => {
      ElMessage.success('测试通知已提交')
      loadList()
    })
  })
}

const sizeChangeHandle = (val: number) => {
  page.value = 1
  pageSize.value = val
  loadList()
}

const currentChangeHandle = (val: number) => {
  page.value = val
  loadList()
}

onMounted(loadList)
</script>

<template>
  <el-card>
    <div class="header">
      <div>
        <h3 class="title">客诉受理</h3>
        <p class="desc">商家售后反馈渠道。将分享地址填写到企业微信成员对外资料的自定义网页字段，用于接收客户售后问题。</p>
      </div>
      <el-button type="primary" :icon="Plus" @click="showCreate">新建渠道</el-button>
    </div>
    <el-table
      :data="list"
      v-loading="listLoading"
      :header-cell-style="{
        'text-align': 'center',
        'background-color': '#F1F1F1',
        height: '50px',
        color: '#333'
      }"
      :cell-style="{ padding: '0', 'text-align': 'center', height: '50px', color: '#333' }"
      row-key="id"
    >
      <el-table-column prop="name" label="渠道名称" min-width="140"></el-table-column>
      <el-table-column prop="operator_name" label="运营主体" min-width="140"></el-table-column>
      <el-table-column label="域名状态" width="120">
        <template #default="{ row }">
          <el-tag :type="row.domain_available ? 'success' : 'danger'">
            {{ row.domain_available ? '可用' : '域名不可用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="分享地址" min-width="240" show-overflow-tooltip>
        <template #default="{ row }">
          <span>{{ row.share_url ? row.share_url : '域名不可用' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status ? 'success' : 'info'">{{ row.status ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="Webhook" width="100">
        <template #default="{ row }">
          <el-tag :type="row.webhook_configured ? 'success' : 'info'">
            {{ row.webhook_configured ? '已配置' : '未配置' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="280" fixed="right">
        <template #default="{ row }">
          <el-button type="primary" link @click="showEdit(row)">编辑</el-button>
          <el-button type="primary" link @click="toggleStatus(row)">{{ row.status ? '停用' : '启用' }}</el-button>
          <el-button type="primary" link :disabled="!row.share_url" @click="copyShareUrl(row)">复制</el-button>
          <el-button type="primary" link :disabled="!row.webhook_configured" @click="testNotification(row)">
            测试通知
          </el-button>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="暂无客诉受理渠道"></el-empty>
      </template>
    </el-table>
    <el-pagination
      :current-page="page"
      :total="total"
      :page-sizes="pageSizes"
      :page-size="pageSize"
      background
      layout="total, sizes, prev, pager, next"
      @size-change="sizeChangeHandle"
      @current-change="currentChangeHandle"
    />
  </el-card>

  <form-modal
    v-if="formVisible === true"
    v-model:visible="formVisible"
    :loading="formLoading"
    :detail="detailData"
    @submit="submitChannel"
    type="dialog"
    width="720"
    :title="detailData?.id ? '编辑渠道' : '新建渠道'"
    cancelBtnName="取消"
    okBtnName="确定"
  >
    <channel-form @clear-webhook="clearWebhook"></channel-form>
  </form-modal>
</template>

<style scoped lang="scss">
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 20px;
}
.title {
  margin: 0 0 8px;
  font-size: 18px;
  color: #333;
}
.desc {
  margin: 0;
  max-width: 720px;
  color: #666;
  line-height: 1.5;
}
</style>
