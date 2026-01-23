import apiClient from './client';
import type { LoginCredentials, RegisterData, AuthResponse, User } from '../types/auth';

export const authApi = {
    async register(data: RegisterData): Promise<AuthResponse> {
        const response = await apiClient.post<AuthResponse>('/register', data);
        return response.data;
    },

    async login(credentials: LoginCredentials): Promise<AuthResponse> {
        const response = await apiClient.post<AuthResponse>('/login', credentials);
        return response.data;
    },

    async getUser(): Promise<{ user: User }> {
        const response = await apiClient.get<{ user: User }>('/user');
        return response.data;
    },

    async logout(): Promise<void> {
        await apiClient.post('/logout');
    },
};
