import client from './client';
import type { ApiResponse, Note } from '@/types';

export interface NotePayload {
  notable_type: 'lead' | 'deal';
  notable_id: number;
  content: string;
  is_pinned?: boolean;
}

export const notesApi = {
  list: (params: { notable_type: 'lead' | 'deal'; notable_id: number }) =>
    client.get<ApiResponse<Note[]>>('/notes', { params }).then((r) => r.data.data),

  create: (payload: NotePayload) =>
    client.post<ApiResponse<Note>>('/notes', payload).then((r) => r.data.data),

  update: (id: number, payload: { content?: string; is_pinned?: boolean }) =>
    client.put<ApiResponse<Note>>(`/notes/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/notes/${id}`).then((r) => r.data),
};
