import http from '@/utils/http'
import type {
  FeedbackChannel,
  FeedbackChannelListResponse,
  FeedbackChannelPayload,
  FeedbackChannelStatusPayload,
  FeedbackTestNotificationResult,
  FeedbackTicketDetail,
  FeedbackTicketListQuery,
  FeedbackTicketListResponse,
  FeedbackTicketNotePayload,
  FeedbackTicketStatusPayload
} from '@/models/feedback'
import { FEEDBACK_CHANNELS_URL, FEEDBACK_TICKETS_URL } from '@/models/feedback'

export const ApiFeedbackChannels = (params?: { page?: number; page_size?: number }) => {
  return http.get<FeedbackChannelListResponse>(FEEDBACK_CHANNELS_URL, { params })
}

export const ApiFeedbackChannelDetail = (id: number) => {
  return http.get<FeedbackChannel>(`${FEEDBACK_CHANNELS_URL}/${id}`)
}

export const ApiCreateFeedbackChannel = (data: FeedbackChannelPayload) => {
  return http.post<FeedbackChannel, FeedbackChannelPayload>(FEEDBACK_CHANNELS_URL, data)
}

export const ApiUpdateFeedbackChannel = (id: number, data: FeedbackChannelPayload) => {
  return http.put<FeedbackChannel, FeedbackChannelPayload>(`${FEEDBACK_CHANNELS_URL}/${id}`, data)
}

export const ApiFeedbackChannelStatus = (id: number, data: FeedbackChannelStatusPayload) => {
  return http.patch<FeedbackChannel, FeedbackChannelStatusPayload>(`${FEEDBACK_CHANNELS_URL}/${id}/status`, data)
}

export const ApiFeedbackChannelTestNotification = (id: number) => {
  return http.post<FeedbackTestNotificationResult, Record<string, never>>(
    `${FEEDBACK_CHANNELS_URL}/${id}/test-notification`
  )
}

export const ApiFeedbackTickets = (params?: FeedbackTicketListQuery) => {
  return http.get<FeedbackTicketListResponse>(FEEDBACK_TICKETS_URL, { params })
}

export const ApiFeedbackTicketDetail = (id: number) => {
  return http.get<FeedbackTicketDetail>(`${FEEDBACK_TICKETS_URL}/${id}`)
}

export const ApiFeedbackTicketStatus = (id: number, data: FeedbackTicketStatusPayload) => {
  return http.patch<FeedbackTicketDetail, FeedbackTicketStatusPayload>(`${FEEDBACK_TICKETS_URL}/${id}/status`, data)
}

export const ApiFeedbackTicketNote = (id: number, data: FeedbackTicketNotePayload) => {
  return http.post<FeedbackTicketDetail, FeedbackTicketNotePayload>(`${FEEDBACK_TICKETS_URL}/${id}/notes`, data)
}

export const ApiFeedbackAttachmentBlob = (url: string) => {
  return http.request<Blob>('GET', url, { responseType: 'blob' })
}
