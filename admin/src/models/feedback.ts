export interface FeedbackChannel {
  id: number
  domain_id: number
  name: string
  operator_name: string
  categories: string[]
  contact_required: boolean
  retention_days: number
  status: boolean
  share_url: string | null
  domain_available: boolean
  webhook_configured: boolean
}

export interface FeedbackChannelForm {
  domain_id: number | undefined
  name: string
  operator_name: string
  intro: string
  service_phone: string
  sla_text: string
  categories: string[]
  contact_required: boolean
  retention_days: number
  webhook_url: string
}

export interface FeedbackChannelPayload {
  domain_id: number
  name: string
  operator_name: string
  intro?: string | null
  service_phone?: string | null
  sla_text: string
  categories: string[]
  contact_required: boolean
  retention_days: number
  webhook_url?: string | null
}

export interface FeedbackChannelListResponse {
  data: FeedbackChannel[]
  meta: {
    total: number
    current_page: number
    last_page: number
  }
}

export interface FeedbackChannelStatusPayload {
  status: boolean
}

export interface FeedbackTestNotificationResult {
  kind: string
  status: string
}

export type FeedbackTicketStatus = 'pending' | 'processing' | 'resolved' | 'closed'

export type FeedbackNotificationStatus = 'pending' | 'sent' | 'failed'

export interface FeedbackTicketListItem {
  id: number
  public_no: string
  category: string
  status: FeedbackTicketStatus
  submitted_at: string
  notification_status: FeedbackNotificationStatus | null
  channel_id: number
  channel_name?: string | null
}

export interface FeedbackTicketAttachment {
  id: number
  original_name: string
  mime: string
  size: number
  download_url: string
}

export interface FeedbackTicketEvent {
  id: number
  event: string
  from_status: string | null
  to_status: string | null
  note: string | null
  actor_user_id: number | null
  created_at: string
}

export interface FeedbackTicketDetail extends FeedbackTicketListItem {
  contact: string | null
  content: string
  resolved_at: string | null
  attachments: FeedbackTicketAttachment[]
  events: FeedbackTicketEvent[]
}

export interface FeedbackTicketListQuery {
  page?: number
  page_size?: number
  status?: FeedbackTicketStatus
  channel_id?: number
  category?: string
  date?: string
}

export interface FeedbackTicketListResponse {
  data: FeedbackTicketListItem[]
  meta: {
    total: number
    current_page: number
    last_page: number
  }
}

export interface FeedbackTicketStatusPayload {
  status: FeedbackTicketStatus
}

export interface FeedbackTicketNotePayload {
  note: string
}

export const FEEDBACK_CHANNELS_URL = '/feedback-channels'
export const FEEDBACK_TICKETS_URL = '/feedback-tickets'

export const FEEDBACK_DEFAULT_CATEGORIES = ['售前承诺', '订单履约', '退款售后', '服务态度', '产品问题', '其他']

export const FEEDBACK_NAME_MAX = 80
export const FEEDBACK_OPERATOR_MAX = 80
export const FEEDBACK_INTRO_MAX = 500
export const FEEDBACK_PHONE_MAX = 32
export const FEEDBACK_SLA_MAX = 80
export const FEEDBACK_CATEGORY_MAX = 20
export const FEEDBACK_CATEGORY_MIN_COUNT = 1
export const FEEDBACK_CATEGORY_MAX_COUNT = 10
export const FEEDBACK_RETENTION_MIN = 30
export const FEEDBACK_RETENTION_MAX = 365
export const FEEDBACK_RETENTION_DEFAULT = 180
export const FEEDBACK_WEBHOOK_MAX = 2048
export const FEEDBACK_NOTE_MIN = 1
export const FEEDBACK_NOTE_MAX = 1000
export const FEEDBACK_PAGE_SIZE_DEFAULT = 10
export const FEEDBACK_PAGE_SIZE_MAX = 100
export const FEEDBACK_COMPACT_MAX_WIDTH = 700
export const FEEDBACK_ATTACHMENT_FALLBACK_NAME = 'attachment'
export const FEEDBACK_ATTACHMENT_DOWNLOAD_MARKER = '/feedback-attachments/'

export const FEEDBACK_TICKET_STATUS_LABELS: Record<FeedbackTicketStatus, string> = {
  pending: '待处理',
  processing: '处理中',
  resolved: '已解决',
  closed: '已关闭'
}

export const FEEDBACK_NOTIFICATION_STATUS_LABELS: Record<FeedbackNotificationStatus, string> = {
  pending: '待发送',
  sent: '已发送',
  failed: '发送失败'
}

export const FEEDBACK_TICKET_TRANSITIONS: Record<FeedbackTicketStatus, FeedbackTicketStatus[]> = {
  pending: ['processing'],
  processing: ['resolved'],
  resolved: ['closed', 'processing'],
  closed: []
}

export const FEEDBACK_TICKET_STATUS_TAG: Record<FeedbackTicketStatus, 'warning' | '' | 'success' | 'info'> = {
  pending: 'warning',
  processing: '',
  resolved: 'success',
  closed: 'info'
}

export const FEEDBACK_NOTIFICATION_STATUS_TAG: Record<FeedbackNotificationStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  sent: 'success',
  failed: 'danger'
}

export function feedbackTicketActionLabel(from: FeedbackTicketStatus, to: FeedbackTicketStatus): string {
  if (from === 'resolved' && to === 'processing') {
    return '重新打开'
  }
  if (to === 'processing') {
    return '开始处理'
  }
  if (to === 'resolved') {
    return '标记已解决'
  }
  if (to === 'closed') {
    return '关闭工单'
  }
  return '更新状态'
}

export function authorizedAttachmentRequestUrl(downloadUrl: string): string {
  const source = downloadUrl.trim()
  const index = source.indexOf(FEEDBACK_ATTACHMENT_DOWNLOAD_MARKER)
  if (index < 0) {
    throw new Error('附件地址无效')
  }
  const path = source.slice(index).split('#')[0].split('?')[0]
  if (!/^\/feedback-attachments\/\d+\/download$/.test(path)) {
    throw new Error('附件地址无效')
  }
  return path
}

export function safeDownloadFileName(name: string): string {
  const safe = name.replace(/[\r\n/\\]/g, '').trim()
  return safe === '' ? FEEDBACK_ATTACHMENT_FALLBACK_NAME : safe
}
