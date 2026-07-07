import axios from 'axios';
import { useAuthStore } from '@/store/authStore';
import { queryClient } from '@/lib/queryClient';

const client = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8002/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false,
});

// Attach token from localStorage on every request
client.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('crm_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  }
  return config;
});

// Global response error handler
client.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const code   = error.response?.data?.code as string | undefined;

    if (status === 401 && typeof window !== 'undefined') {
      // Unauthenticated — clear session and redirect to login
      useAuthStore.getState().clearAuth();
      window.location.href = '/login';
    }

    if (status === 403 && (code === 'UPGRADE_REQUIRED' || code === 'NO_SUBSCRIPTION')) {
      // Subscription expired or plan downgraded — force-refresh the cached sub data.
      // The dashboard layout's useEffect will sync it into the auth store, which
      // then updates isBusinessPlan() / isEnterprisePlan() and shows the expiry UI.
      queryClient.invalidateQueries({ queryKey: ['subscription'] });
    }

    return Promise.reject(error);
  }
);

export default client;
