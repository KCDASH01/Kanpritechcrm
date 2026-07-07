import client from './client';
import type { Proposal, ProposalContent, ProposalStatus, ProposalTheme } from '@/types';

export const proposalsApi = {
  generate: (leadId: number, notes: string, theme: ProposalTheme) =>
    client
      .post<{ data: Proposal }>(`/leads/${leadId}/proposals/generate`, { notes, theme })
      .then((r) => r.data.data),

  list: (leadId: number) =>
    client
      .get<{ data: Proposal[] }>(`/leads/${leadId}/proposals`)
      .then((r) => r.data.data),

  show: (id: number) =>
    client
      .get<{ data: Proposal }>(`/proposals/${id}`)
      .then((r) => r.data.data),

  update: (
    id: number,
    payload: Partial<{
      content: ProposalContent;
      status: ProposalStatus;
      valid_until: string | null;
      title: string;
      theme: ProposalTheme;
    }>
  ) =>
    client
      .put<{ data: Proposal }>(`/proposals/${id}`, payload)
      .then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/proposals/${id}`).then((r) => r.data),
};
