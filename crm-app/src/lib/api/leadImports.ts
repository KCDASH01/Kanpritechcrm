import client from './client';

export interface ImportSetup {
  sheet: number;
  columns: Record<string, string>;
  defaults: Record<string, string | null>;
  service_aliases: Record<string, string>;
  date_format: 'd/m' | 'm/d';
  fill_dates: boolean;
  revision?: string;
  date_fallback?: string;
}

export interface ImportSheet {
  name: string;
  error?: string;
  headers?: string[];
  sample?: string[];
  map?: Record<string, string>;
  conflicts?: number[];
}

export type ImportStatus = 'uploaded' | 'validating' | 'ready' | 'queued' | 'processing' | 'completed' | 'completed_errors' | 'failed';

export interface ImportSummary {
  id: number;
  uploader_name: string;
  filename: string;
  file_size: number;
  format: 'csv' | 'xls' | 'xlsx';
  status: ImportStatus;
  total_rows: number;
  valid_rows: number;
  duplicate_rows: number;
  invalid_rows: number;
  warning_rows: number;
  created_rows: number;
  failed_rows: number;
  error: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
}

export interface ImportDetails extends ImportSummary {
  organization: string;
  can_edit: boolean;
  sheets: ImportSheet[];
  mapping: ImportSetup | null;
  fields: Record<string, string>;
  services: Record<string, string>;
  defaults: Record<string, string | null>;
}

export interface ImportRow {
  source_row: number;
  status: 'ready' | 'invalid' | 'duplicate' | 'failed' | 'created';
  warning: boolean;
  issues: Record<string, string> | null;
  values: Record<string, string | null>;
}

interface Page<T> { data: T[]; current_page: number; last_page: number }

const base = '/leads/imports';
export const leadImportsApi = {
  upload: (file: File) => {
    const form = new FormData();
    form.append('file', file);
    return client.post<{ data: ImportDetails }>(base, form, { headers: { 'Content-Type': undefined } }).then((r) => r.data.data);
  },
  show: (id: number) => client.get<{ data: ImportDetails }>(`${base}/${id}`).then((r) => r.data.data),
  list: (page: number) => client.get<Page<ImportSummary>>(base, { params: { page } }).then((r) => r.data),
  validate: (id: number, setup: ImportSetup) => client.post(`${base}/${id}/validate`, setup),
  confirm: (id: number, revision: string) => client.post(`${base}/${id}/confirm`, { revision }),
  preview: (id: number, page: number, filter: string) => client.get<Page<ImportRow>>(`${base}/${id}/preview`, { params: { page, filter: filter || undefined } }).then((r) => r.data),
  errors: (id: number) => client.get<Blob>(`${base}/${id}/errors`, { responseType: 'blob' }).then((r) => r.data),
  template: () => client.get<Blob>(`${base}/template`, { responseType: 'blob' }).then((r) => r.data),
};
