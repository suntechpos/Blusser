
import axios from 'axios'

export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

export interface AuthUser {
  id: string
  login: string
  is_platform_admin: boolean
  must_change_password: boolean
  active_tenant_id: string | null
}

export async function loginUser(login: string, password: string): Promise<AuthUser> {
  await api.get('/sanctum/csrf-cookie')

  const { data } = await api.post<AuthUser>('/api/v1/auth/login', {
    login,
    password,
  })

  return data
}

export async function getCurrentUser(): Promise<AuthUser> {
  const { data } = await api.get<AuthUser>('/api/v1/auth/me')
  return data
}
export async function logoutUser(): Promise<void> {
  await api.post('/api/v1/auth/logout')
}