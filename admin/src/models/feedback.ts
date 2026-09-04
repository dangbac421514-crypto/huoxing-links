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

export const FEEDBACK_CHANNELS_URL = '/feedback-channels'

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
