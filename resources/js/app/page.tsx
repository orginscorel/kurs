import { Suspense, type ReactNode } from 'react'
import { PageSkeleton } from '@/components/layout/PageSkeleton'

/** Tembel yüklenen sayfayı iskelet yükleme ile sarar. */
export function page(node: ReactNode) {
  return <Suspense fallback={<PageSkeleton />}>{node}</Suspense>
}
