import client from './client';
import type { ApiResponse, User } from '@/types';

export interface LoginPayload {
  email: string;
  password: string;
}

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  organization_name?: string;
  timezone?: string;
}

export interface AuthResponse {
  token: string;
  user: User;
}

export const authApi = {
  login: (payload: LoginPayload) =>
    client.post<AuthResponse>('/auth/login', payload).then((r) => r.data),

  ssoLogin: (token: string) =>
    client.post<AuthResponse>('/sso/login', { token }).then((r) => r.data),

  register: (payload: RegisterPayload) =>
    client.post<AuthResponse>('/auth/register', payload).then((r) => r.data),

  logout: () =>
    client.post('/auth/logout').then((r) => r.data),

  me: () =>
    client.get<ApiResponse<User>>('/me').then((r) => r.data.data),

  updateMe: (data: Partial<{ name: string; phone: string; avatar: string }>) =>
    client.put<ApiResponse<User>>('/me', data).then((r) => r.data.data),

  changePassword: (data: { current_password: string; password: string; password_confirmation: string }) =>
    client.put('/me/password', data).then((r) => r.data),

  setPassword: (password: string, password_confirmation: string) =>
    client.post<ApiResponse<User>>('/me/set-password', { password, password_confirmation }).then((r) => r.data.data),

  forgotPassword: (email: string) =>
    client.post('/auth/forgot-password', { email }).then((r) => r.data),

  resetPassword: (data: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => client.post('/auth/reset-password', data).then((r) => r.data),
};
