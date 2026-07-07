import client from './client';
import type { ApiResponse, Pipeline, Stage } from '@/types';

export const pipelinesApi = {
  list: () =>
    client.get<ApiResponse<Pipeline[]>>('/pipelines').then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<Pipeline>>(`/pipelines/${id}`).then((r) => r.data.data),

  create: (payload: { name: string; description?: string; is_default?: boolean }) =>
    client.post<ApiResponse<Pipeline>>('/pipelines', payload).then((r) => r.data.data),

  update: (id: number, payload: { name?: string; description?: string }) =>
    client.put<ApiResponse<Pipeline>>(`/pipelines/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/pipelines/${id}`).then((r) => r.data),

  // Stages
  createStage: (pipelineId: number, payload: {
    name: string; color?: string; sort_order?: number; probability?: number; is_won?: boolean; is_lost?: boolean;
  }) =>
    client.post<ApiResponse<Stage>>(`/pipelines/${pipelineId}/stages`, payload).then((r) => r.data.data),

  updateStage: (pipelineId: number, stageId: number, payload: Partial<Stage>) =>
    client.put<ApiResponse<Stage>>(`/pipelines/${pipelineId}/stages/${stageId}`, payload).then((r) => r.data.data),

  deleteStage: (pipelineId: number, stageId: number) =>
    client.delete(`/pipelines/${pipelineId}/stages/${stageId}`).then((r) => r.data),

  reorderStages: (pipelineId: number, order: number[]) =>
    client.post<ApiResponse<Stage[]>>(`/pipelines/${pipelineId}/stages/reorder`, { order }).then((r) => r.data.data),
};
