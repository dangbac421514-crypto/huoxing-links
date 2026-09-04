import http from '@/utils/http'
import type {
  FeedbackChannel,
  FeedbackChannelListResponse,
  FeedbackChannelPayload,
  FeedbackChannelStatusPayload,
  FeedbackTestNotificationResult
} from '@/models/feedback'
import { FEEDBACK_CHANNELS_URL } from '@/models/feedback'

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
