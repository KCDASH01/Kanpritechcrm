import client from './client';
import type {
  LeadReportFilters,
  LeadReportRow,
  LeadReportSummary,
  PaginatedResponse,
  ReportsData,
  RevenueReportFilters,
  RevenueReportRow,
  RevenueReportSummary,
} from '@/types';

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export const reportsApi = {
  get: (params?: { assigned_to?: number }) =>
    client.get<{ data: ReportsData }>('/reports', { params }).then((r) => r.data.data),

  leadReport: (filters?: LeadReportFilters) =>
    client
      .get<{
        data: { summary: LeadReportSummary; rows: LeadReportRow[] };
        meta: PaginatedResponse<LeadReportRow>['meta'];
      }>('/reports/leads', { params: filters })
      .then((r) => r.data),

  revenueReport: (filters?: RevenueReportFilters) =>
    client
      .get<{
        data: { summary: RevenueReportSummary; rows: RevenueReportRow[] };
        meta: PaginatedResponse<RevenueReportRow>['meta'];
      }>('/reports/revenue', { params: filters })
      .then((r) => r.data),

  exportLeadReport: async (filters: LeadReportFilters, format: 'csv' | 'xlsx') => {
    const response = await client.get('/reports/leads/export', {
      params: { ...filters, format },
      responseType: 'blob',
    });
    const ext = format === 'xlsx' ? 'xls' : 'csv';
    downloadBlob(response.data, `lead-report-${new Date().toISOString().slice(0, 10)}.${ext}`);
  },

  exportRevenueReport: async (filters: RevenueReportFilters, format: 'csv' | 'xlsx') => {
    const response = await client.get('/reports/revenue/export', {
      params: { ...filters, format },
      responseType: 'blob',
    });
    const ext = format === 'xlsx' ? 'xls' : 'csv';
    downloadBlob(response.data, `revenue-report-${new Date().toISOString().slice(0, 10)}.${ext}`);
  },
};
