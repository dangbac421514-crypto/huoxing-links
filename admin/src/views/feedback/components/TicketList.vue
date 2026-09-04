<script setup lang="ts">
import { useWindowSize } from '@vueuse/core'
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiFeedbackChannels, ApiFeedbackTickets } from '@/api/feedback'
import type { FeedbackChannel, FeedbackTicketListItem, FeedbackTicketListQuery, FeedbackTicketStatus } from '@/models/feedback'
import {
  FEEDBACK_COMPACT_MAX_WIDTH,
  FEEDBACK_DEFAULT_CATEGORIES,
  FEEDBACK_NOTIFICATION_STATUS_LABELS,
  FEEDBACK_NOTIFICATION_STATUS_TAG,
  FEEDBACK_PAGE_SIZE_DEFAULT,
  FEEDBACK_PAGE_SIZE_MAX,
  FEEDBACK_TICKET_STATUS_LABELS,
  FEEDBACK_TICKET_STATUS_TAG
} from '@/models/feedback'

const emit = defineEmits<{
  (e: 'open', row: FeedbackTicketListItem): void
}>()

const { width: windowWidth } = useWindowSize()
const isCompact = computed(() => windowWidth.value <= FEEDBACK_COMPACT_MAX_WIDTH)
const list = ref<FeedbackTicketListItem[]>([])
const listLoading = ref(false)
const page = ref(1)
const pageSize = ref(FEEDBACK_PAGE_SIZE_DEFAULT)
const total = ref(0)
const pageSizes = [10, 20, 30, 40, 50]
const channels = ref<FeedbackChannel[]>([])
const filters = reactive({
  status: '' as FeedbackTicketStatus | '',
  channel_id: undefined as number | undefined,
  category: '',
  date: ''
})

const statusOptions: FeedbackTicketStatus[] = ['pending', 'processing', 'resolved', 'closed']

const categoryOptions = computed(() => {
  const names = new Set<string>()
  const selected = channels.value.find((item) => item.id === filters.channel_id)
  if (selected?.categories?.length) {
    selected.categories.forEach((name) => names.add(name))
  } else {
    FEEDBACK_DEFAULT_CATEGORIES.forEach((name) => names.add(name))
    channels.value.forEach((channel) => {
      channel.categories?.forEach((name) => names.add(name))
    })
  }
  list.value.forEach((row) => {
    if (row.category) {
      names.add(row.category)
    }
  })
  return [...names]
})

const buildQuery = (): FeedbackTicketListQuery => {
  const params: FeedbackTicketListQuery = {
    page: page.value,
    page_size: pageSize.value
  }
  if (filters.status) {
    params.status = filters.status
  }
  if (filters.channel_id) {
    params.channel_id = filters.channel_id
  }
  if (filters.category) {
    params.category = filters.category
  }
  if (filters.date) {
    params.date = filters.date
  }
  return params
}

const loadList = () => {
  listLoading.value = true
  ApiFeedbackTickets(buildQuery())
    .then((res) => {
      list.value = res.data
      total.value = res.meta.total
    })
    .finally(() => {
      listLoading.value = false
    })
}

const loadChannels = () => {
  ApiFeedbackChannels({ page: 1, page_size: FEEDBACK_PAGE_SIZE_MAX }).then((res) => {
    channels.value = res.data
  })
}

const search = () => {
  page.value = 1
  loadList()
}

const resetFilters = () => {
  filters.status = ''
  filters.channel_id = undefined
  filters.category = ''
  filters.date = ''
  page.value = 1
  loadList()
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

const openTicket = (row: FeedbackTicketListItem) => {
  emit('open', row)
}

const notificationLabel = (row: FeedbackTicketListItem) => {
  if (!row.notification_status) {
    return '无'
  }
  return FEEDBACK_NOTIFICATION_STATUS_LABELS[row.notification_status]
}

const channelLabel = (row: FeedbackTicketListItem) => {
  return row.channel_name ? row.channel_name : '—'
}

onMounted(() => {
  loadChannels()
  loadList()
})

defineExpose({
  reload: loadList,
  reloadChannels: loadChannels
})
</script>

<template>
  <el-card class="ticket-list" :class="{ compact: isCompact }">
    <div class="header">
      <div>
        <h3 class="title">工单</h3>
        <p class="desc">按状态、渠道、分类和提交日期筛选客诉工单。列表不展示联系方式和问题正文。</p>
      </div>
    </div>
    <div class="filters">
      <el-select v-model="filters.status" clearable placeholder="状态" class="filter-control">
        <el-option v-for="item in statusOptions" :key="item" :label="FEEDBACK_TICKET_STATUS_LABELS[item]" :value="item" />
      </el-select>
      <el-select v-model="filters.channel_id" clearable placeholder="渠道" class="filter-control">
        <el-option v-for="item in channels" :key="item.id" :label="item.name" :value="item.id" />
      </el-select>
      <el-select v-model="filters.category" clearable placeholder="分类" class="filter-control">
        <el-option v-for="item in categoryOptions" :key="item" :label="item" :value="item" />
      </el-select>
      <el-date-picker
        v-model="filters.date"
        type="date"
        value-format="YYYY-MM-DD"
        placeholder="提交日期"
        class="filter-control filter-date"
      />
      <div class="filter-actions">
        <el-button type="primary" class="filter-btn" @click="search">查询</el-button>
        <el-button class="filter-btn" @click="resetFilters">重置</el-button>
      </div>
    </div>

    <div v-if="isCompact" class="ticket-cards" v-loading="listLoading">
      <article
        v-for="row in list"
        :key="row.id"
        class="ticket-card"
        @click="openTicket(row)"
      >
        <div class="ticket-card-row">
          <span class="label">工单号</span>
          <span class="value">{{ row.public_no }}</span>
        </div>
        <div class="ticket-card-row">
          <span class="label">渠道</span>
          <span class="value">{{ channelLabel(row) }}</span>
        </div>
        <div class="ticket-card-row">
          <span class="label">分类</span>
          <span class="value">{{ row.category }}</span>
        </div>
        <div class="ticket-card-row">
          <span class="label">状态</span>
          <span class="value">{{ FEEDBACK_TICKET_STATUS_LABELS[row.status] }}</span>
        </div>
        <div class="ticket-card-row">
          <span class="label">通知</span>
          <span class="value">{{ notificationLabel(row) }}</span>
        </div>
        <div class="ticket-card-row">
          <span class="label">时间</span>
          <span class="value">{{ row.submitted_at }}</span>
        </div>
      </article>
      <el-empty v-if="!listLoading && list.length === 0" description="暂无客诉工单"></el-empty>
    </div>

    <el-table
      v-else
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
      @row-click="openTicket"
    >
      <el-table-column prop="public_no" label="工单号" min-width="160">
        <template #default="{ row }">
          <el-button type="primary" link @click.stop="openTicket(row)">{{ row.public_no }}</el-button>
        </template>
      </el-table-column>
      <el-table-column label="渠道" min-width="140">
        <template #default="{ row }">{{ channelLabel(row) }}</template>
      </el-table-column>
      <el-table-column prop="category" label="分类" min-width="120"></el-table-column>
      <el-table-column label="状态" width="110">
        <template #default="{ row }">
          <el-tag :type="FEEDBACK_TICKET_STATUS_TAG[row.status]">{{ FEEDBACK_TICKET_STATUS_LABELS[row.status] }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="通知" width="120">
        <template #default="{ row }">
          <el-tag v-if="row.notification_status" :type="FEEDBACK_NOTIFICATION_STATUS_TAG[row.notification_status]">
            {{ FEEDBACK_NOTIFICATION_STATUS_LABELS[row.notification_status] }}
          </el-tag>
          <span v-else>无</span>
        </template>
      </el-table-column>
      <el-table-column prop="submitted_at" label="时间" min-width="170"></el-table-column>
      <template #empty>
        <el-empty description="暂无客诉工单"></el-empty>
      </template>
    </el-table>

    <el-pagination
      :current-page="page"
      :total="total"
      :page-sizes="pageSizes"
      :page-size="pageSize"
      background
      :layout="isCompact ? 'total, prev, pager, next' : 'total, sizes, prev, pager, next'"
      @size-change="sizeChangeHandle"
      @current-change="currentChangeHandle"
    />
  </el-card>
</template>

<style scoped lang="scss">
.ticket-list {
  overflow-x: hidden;
}
.header {
  margin-bottom: 16px;
}
.title {
  margin: 0 0 8px;
  font-size: 18px;
  color: #333;
}
.desc {
  margin: 0;
  color: #666;
  line-height: 1.5;
}
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
  align-items: center;
}
.filter-control {
  width: 160px;
}
.filter-date {
  width: 180px;
}
.filter-actions {
  display: flex;
  gap: 8px;
}
.ticket-cards {
  overflow-x: hidden;
}
.ticket-card {
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 12px;
  cursor: pointer;
  overflow-x: hidden;
}
.ticket-card-row {
  display: flex;
  gap: 8px;
  padding: 4px 0;
  min-width: 0;
}
.ticket-card-row .label {
  flex: 0 0 56px;
  color: #666;
}
.ticket-card-row .value {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.compact {
  :deep(.el-input__inner),
  :deep(.el-select .el-input__inner),
  :deep(input),
  :deep(textarea) {
    font-size: 16px !important;
  }
  .filter-control,
  .filter-date {
    width: 100%;
  }
  .filter-actions {
    width: 100%;
  }
  .filter-btn {
    min-height: 44px;
    flex: 1;
  }
}
</style>
