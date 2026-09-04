<script setup lang="ts">
import { useWindowSize } from '@vueuse/core'
import { ElMessage } from 'element-plus'
import { computed, ref, watch } from 'vue'
import {
  ApiFeedbackAttachmentBlob,
  ApiFeedbackTicketDetail,
  ApiFeedbackTicketNote,
  ApiFeedbackTicketStatus
} from '@/api/feedback'
import type { FeedbackTicketAttachment, FeedbackTicketDetail, FeedbackTicketEvent, FeedbackTicketStatus } from '@/models/feedback'
import {
  authorizedAttachmentRequestUrl,
  FEEDBACK_COMPACT_MAX_WIDTH,
  FEEDBACK_NOTE_MAX,
  FEEDBACK_NOTE_MIN,
  FEEDBACK_NOTIFICATION_STATUS_LABELS,
  FEEDBACK_TICKET_STATUS_LABELS,
  FEEDBACK_TICKET_STATUS_TAG,
  FEEDBACK_TICKET_TRANSITIONS,
  feedbackTicketActionLabel,
  safeDownloadFileName
} from '@/models/feedback'

const props = defineProps<{
  visible: boolean
  ticketId: number | null
}>()

const emit = defineEmits<{
  (e: 'update:visible', visible: boolean): void
  (e: 'updated'): void
}>()

const { width: windowWidth } = useWindowSize()
const isCompact = computed(() => windowWidth.value <= FEEDBACK_COMPACT_MAX_WIDTH)
const drawerSize = computed(() => (isCompact.value ? '100vw' : '560px'))
const detail = ref<FeedbackTicketDetail | null>(null)
const detailLoading = ref(false)
const actionLoading = ref(false)
const noteSubmitting = ref(false)
const note = ref('')
const requestedId = ref<number | null>(null)

const nextStatuses = computed((): FeedbackTicketStatus[] => {
  if (!detail.value) {
    return []
  }
  return FEEDBACK_TICKET_TRANSITIONS[detail.value.status]
})

const notificationText = computed(() => {
  const status = detail.value?.notification_status
  if (!status) {
    return '无'
  }
  return FEEDBACK_NOTIFICATION_STATUS_LABELS[status]
})

const isBoundDetail = (id: number) => {
  return requestedId.value === id && detail.value?.id === id
}

const loadDetail = (id: number) => {
  requestedId.value = id
  detail.value = null
  note.value = ''
  actionLoading.value = false
  noteSubmitting.value = false
  detailLoading.value = true
  ApiFeedbackTicketDetail(id)
    .then((payload) => {
      if (requestedId.value !== id) {
        return
      }
      detail.value = payload
    })
    .finally(() => {
      if (requestedId.value === id) {
        detailLoading.value = false
      }
    })
}

const reloadBoundDetail = (id: number) => {
  return ApiFeedbackTicketDetail(id).then((payload) => {
    if (props.visible && requestedId.value === id) {
      detail.value = payload
    }
    emit('updated')
    return payload
  })
}

watch(
  () => [props.visible, props.ticketId] as const,
  ([visible, id]) => {
    if (!visible || id === null) {
      requestedId.value = null
      detail.value = null
      note.value = ''
      detailLoading.value = false
      actionLoading.value = false
      noteSubmitting.value = false
      return
    }
    loadDetail(id)
  }
)

const closeDrawer = () => {
  emit('update:visible', false)
}

const changeStatus = (to: FeedbackTicketStatus) => {
  const id = props.ticketId
  if (id === null || actionLoading.value || detailLoading.value || !isBoundDetail(id)) {
    return
  }
  if (!nextStatuses.value.includes(to)) {
    return
  }
  actionLoading.value = true
  ApiFeedbackTicketStatus(id, { status: to })
    .then(() => reloadBoundDetail(id))
    .then(() => {
      ElMessage.success('状态已更新')
    })
    .finally(() => {
      if (requestedId.value === id) {
        actionLoading.value = false
      }
    })
}

const submitNote = () => {
  const id = props.ticketId
  const text = note.value
  if (id === null || noteSubmitting.value || detailLoading.value || !isBoundDetail(id)) {
    return
  }
  if (text.length < FEEDBACK_NOTE_MIN || text.length > FEEDBACK_NOTE_MAX) {
    ElMessage.error(`内部备注需为${FEEDBACK_NOTE_MIN}-${FEEDBACK_NOTE_MAX}个字`)
    return
  }
  noteSubmitting.value = true
  ApiFeedbackTicketNote(id, { note: text })
    .then(() => reloadBoundDetail(id))
    .then(() => {
      if (requestedId.value === id) {
        note.value = ''
      }
      ElMessage.success('备注已保存')
    })
    .finally(() => {
      if (requestedId.value === id) {
        noteSubmitting.value = false
      }
    })
}

const downloadAttachment = (attachment: FeedbackTicketAttachment) => {
  let url = ''
  try {
    url = authorizedAttachmentRequestUrl(attachment.download_url)
  } catch {
    ElMessage.error('附件不可用')
    return
  }
  const filename = safeDownloadFileName(attachment.original_name)
  ApiFeedbackAttachmentBlob(url).then((blob) => {
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = filename
    document.body.appendChild(anchor)
    anchor.click()
    document.body.removeChild(anchor)
    window.setTimeout(() => {
      URL.revokeObjectURL(objectUrl)
    }, 0)
  })
}

const eventTitle = (event: FeedbackTicketEvent) => {
  if (event.event === 'note_added') {
    return '内部备注'
  }
  if (event.event === 'status_changed') {
    const fromLabel = event.from_status
      ? FEEDBACK_TICKET_STATUS_LABELS[event.from_status as FeedbackTicketStatus] ?? event.from_status
      : ''
    const toLabel = event.to_status
      ? FEEDBACK_TICKET_STATUS_LABELS[event.to_status as FeedbackTicketStatus] ?? event.to_status
      : ''
    return `状态：${fromLabel} → ${toLabel}`
  }
  if (event.event === 'submitted') {
    return '客户提交'
  }
  return event.event
}
</script>

<template>
  <el-drawer
    :model-value="visible"
    class="feedback-ticket-drawer"
    :class="{ compact: isCompact }"
    :size="drawerSize"
    append-to-body
    direction="rtl"
    :close-on-click-modal="false"
    @close="closeDrawer"
  >
    <template #header>
      <span>{{ detail?.public_no ? detail.public_no : '工单详情' }}</span>
    </template>
    <div class="ticket-detail" :class="{ compact: isCompact }" v-loading="detailLoading">
      <template v-if="detail">
        <div class="field">
          <div class="label">渠道</div>
          <div class="plain">{{ detail.channel_name ? detail.channel_name : '—' }}</div>
        </div>
        <div class="field">
          <div class="label">分类</div>
          <div class="plain">{{ detail.category }}</div>
        </div>
        <div class="field">
          <div class="label">状态</div>
          <el-tag :type="FEEDBACK_TICKET_STATUS_TAG[detail.status]">{{ FEEDBACK_TICKET_STATUS_LABELS[detail.status] }}</el-tag>
        </div>
        <div class="field">
          <div class="label">通知</div>
          <div class="plain">{{ notificationText }}</div>
        </div>
        <div class="field">
          <div class="label">提交时间</div>
          <div class="plain">{{ detail.submitted_at }}</div>
        </div>
        <div class="field">
          <div class="label">联系方式</div>
          <div class="plain">{{ detail.contact ? detail.contact : '未填写' }}</div>
        </div>
        <div class="field">
          <div class="label">问题说明</div>
          <div class="plain body-text">{{ detail.content }}</div>
        </div>
        <div class="field">
          <div class="label">附件</div>
          <div v-if="detail.attachments && detail.attachments.length > 0" class="attachments">
            <el-button
              v-for="item in detail.attachments"
              :key="item.id"
              type="primary"
              link
              class="attach-btn"
              @click="downloadAttachment(item)"
            >
              {{ item.original_name }}
            </el-button>
          </div>
          <div v-else class="plain">无</div>
        </div>
        <div class="field">
          <div class="label">处理记录</div>
          <div v-if="detail.events && detail.events.length > 0" class="events">
            <div v-for="item in detail.events" :key="item.id" class="event">
              <div class="event-meta">{{ item.created_at }} {{ eventTitle(item) }}</div>
              <div v-if="item.note" class="plain body-text">{{ item.note }}</div>
            </div>
          </div>
          <div v-else class="plain">暂无记录</div>
        </div>
        <div class="field">
          <div class="label">内部备注</div>
          <el-input
            v-model="note"
            type="textarea"
            :rows="4"
            :maxlength="FEEDBACK_NOTE_MAX"
            show-word-limit
            class="note-input"
            placeholder="1-1000字，仅内部可见"
          />
          <el-button
            type="primary"
            class="note-submit"
            :disabled="noteSubmitting || detailLoading || actionLoading"
            :loading="noteSubmitting"
            @click="submitNote"
          >
            保存备注
          </el-button>
        </div>
      </template>
    </div>
    <template #footer>
      <div class="drawer-footer" :class="{ compact: isCompact }">
        <el-button
          v-for="to in nextStatuses"
          :key="to"
          type="primary"
          class="status-btn"
          :disabled="actionLoading || detailLoading || noteSubmitting || !detail"
          :loading="actionLoading"
          @click="changeStatus(to)"
        >
          {{ detail ? feedbackTicketActionLabel(detail.status, to) : '' }}
        </el-button>
      </div>
    </template>
  </el-drawer>
</template>

<style scoped lang="scss">
.ticket-detail {
  min-height: 120px;
  overflow-x: hidden;
}
.field {
  margin-bottom: 16px;
}
.label {
  margin-bottom: 6px;
  color: #666;
}
.plain {
  color: #333;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.body-text {
  white-space: pre-wrap;
}
.attachments {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
}
.events {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.event-meta {
  color: #999;
  margin-bottom: 4px;
}
.note-input {
  width: 100%;
}
.note-submit {
  margin-top: 12px;
}
.drawer-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.compact {
  :deep(.el-input__inner),
  :deep(.el-textarea__inner),
  :deep(input),
  :deep(textarea) {
    font-size: 16px !important;
  }
  .note-submit,
  .status-btn {
    min-height: 44px;
    min-width: 44px;
  }
}
</style>

<style lang="scss">
.feedback-ticket-drawer.compact {
  width: 100vw !important;
}
.feedback-ticket-drawer.compact input,
.feedback-ticket-drawer.compact textarea,
.feedback-ticket-drawer.compact .el-input__inner,
.feedback-ticket-drawer.compact .el-textarea__inner {
  font-size: 16px !important;
}
.feedback-ticket-drawer.compact .note-submit,
.feedback-ticket-drawer.compact .status-btn {
  min-height: 44px;
  min-width: 44px;
}
</style>
