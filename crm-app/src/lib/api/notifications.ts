import client from './client';
import type { CrmNotification, PaginatedResponse } from '@/types';

export const notificationsApi = {
  list: (params?: { per_page?: number }) =>
    client.get<PaginatedResponse<CrmNotification>>('/notifications', { params }).then((r) => r.data),

  unreadCount: () =>
    client.get<{ data: { count: number } }>('/notifications/unread-count').then((r) => r.data.data.count),

  markRead: (id: number) =>
    client.patch(`/notifications/${id}/read`).then((r) => r.data),

  markAllRead: () =>
    client.post('/notifications/read-all').then((r) => r.data),
};
