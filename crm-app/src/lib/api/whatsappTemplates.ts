import client from './client';

export interface WhatsAppTemplate {
  id: number;
  name: string;
  message: string;
}

export const whatsappTemplatesApi = {
  list: () =>
    client.get<{ data: WhatsAppTemplate[] }>('/whatsapp-templates')
      .then((r) => r.data.data),
};
