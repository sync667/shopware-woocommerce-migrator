import { useState, useEffect, type ReactNode, useCallback } from 'react';
import { authApi } from '../api/auth';
import type { User, LoginCredentials, RegisterData } from '../types/auth';
import { AuthContext } from './authContextDef';

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        let isMounted = true;
        const token = localStorage.getItem('auth_token');

        if (token) {
            authApi
                .getUser()
                .then(({ user }) => {
                    if (isMounted) {
                        setUser(user);
                    }
                })
                .catch(() => {
                    localStorage.removeItem('auth_token');
                })
                .finally(() => {
                    if (isMounted) {
                        setIsLoading(false);
                    }
                });
        } else {
            // Use setTimeout to avoid synchronous setState in effect
            const timeout = setTimeout(() => {
                if (isMounted) {
                    setIsLoading(false);
                }
            }, 0);
            return () => clearTimeout(timeout);
        }

        return () => {
            isMounted = false;
        };
    }, []);

    const login = useCallback(async (credentials: LoginCredentials) => {
        const response = await authApi.login(credentials);
        localStorage.setItem('auth_token', response.token);
        setUser(response.user);
    }, []);

    const register = useCallback(async (data: RegisterData) => {
        const response = await authApi.register(data);
        localStorage.setItem('auth_token', response.token);
        setUser(response.user);
    }, []);

    const logout = useCallback(async () => {
        await authApi.logout();
        localStorage.removeItem('auth_token');
        setUser(null);
    }, []);

    return (
        <AuthContext.Provider
            value={{
                user,
                isAuthenticated: !!user,
                isLoading,
                login,
                register,
                logout,
            }}
        >
            {children}
        </AuthContext.Provider>
    );
}
