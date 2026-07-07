import client from './client';
import type { ApiResponse, EmailTemplate } from '@/types';

export interface EmailTemplatePayload {
  name: string;
  subject: string;
  body: string;
  stage_trigger?: string;
}

export const emailTemplatesApi = {
  list: () =>
    client.get<ApiResponse<EmailTemplate[]>>('/email-templates').then((r) => r.data.data),

  create: (payload: EmailTemplatePayload) =>
    client.post<ApiResponse<EmailTemplate>>('/email-templates', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<EmailTemplatePayload>) =>
    client.put<ApiResponse<EmailTemplate>>(`/email-templates/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/email-templates/${id}`).then((r) => r.data),
};
