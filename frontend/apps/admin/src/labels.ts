import type { TenantStatus } from '@sertec/api-client'

export const STATUS_LABEL: Record<TenantStatus, string> = {
  pending: 'Em implantação',
  active: 'Ativo',
  suspended: 'Suspenso',
  cancelled: 'Cancelado',
}

export const STATUS_TONE: Record<TenantStatus, string> = {
  pending: 'bg-warning/15 text-warning',
  active: 'bg-success/10 text-success',
  suspended: 'bg-danger-soft text-danger',
  cancelled: 'bg-line text-muted',
}

export function slugify(name: string): string {
  return name
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
    .slice(0, 63)
}
