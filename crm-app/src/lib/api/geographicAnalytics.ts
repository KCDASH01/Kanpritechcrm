import client from './client';
import type {
  GeographicDealRow,
  GeographicFilters,
  GeographicOverview,
  GeographicSummary,
  PaginatedResponse,
} from '@/types';

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export const geographicAnalyticsApi = {
  overview: (filters: GeographicFilters) =>
    client.get<{ data: GeographicOverview }>('/geographic-analytics', { params: filters }).then((response) => response.data.data),

  deals: (filters: GeographicFilters) =>
    client.get<{
      data: { summary: GeographicSummary; rows: GeographicDealRow[] };
      meta: PaginatedResponse<GeographicDealRow>['meta'];
    }>('/geographic-analytics/deals', { params: filters }).then((response) => response.data),

  exportExcel: async (filters: GeographicFilters, scope: 'summary' | 'details') => {
    const response = await client.get('/geographic-analytics/export', {
      params: { ...filters, scope },
      responseType: 'blob',
    });
    downloadBlob(
      response.data,
      `geographic-${filters.market ?? 'international'}-${scope}-${new Date().toISOString().slice(0, 10)}.xls`,
    );
  },
};
