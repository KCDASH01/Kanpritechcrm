import { QueryClient } from '@tanstack/react-query';

/**
 * Module-level singleton so the axios interceptor (which runs outside React)
 * can invalidate queries when the API returns subscription-related 403s.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime:            60_000,     // 1 min — serve cached data without spinner
      gcTime:               5 * 60_000, // 5 min — keep unused data in cache
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status;
        if (status === 401 || status === 403) return false;
        return failureCount < 1;
      },
    },
  },
});
