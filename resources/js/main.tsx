import '@fontsource-variable/inter'
import '../css/app.css'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { Toaster } from 'sonner'
import { ApiError, setUnauthorizedHandler } from '@/lib/api'
import { useAuth } from '@/app/auth'
import { router } from '@/app/router'
import { UpdateNotifier } from '@/components/app/UpdateNotifier'
import { watchSystem } from '@/lib/theme'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      refetchOnWindowFocus: false,
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
    },
    mutations: { retry: false },
  },
})

// Oturum düştüğünde (401) önbelleği temizle, giriş ekranına dön.
setUnauthorizedHandler(() => {
  if (useAuth.getState().status === 'authenticated') {
    queryClient.clear()
    useAuth.getState().setMe(null)
  }
})

// Bilgisayarın açık/koyu ayarı değişince (seçim "Sistem" ise) tüm ekranlar anında uyar — portallar dahil
watchSystem()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <UpdateNotifier />
      <Toaster position="bottom-right" richColors closeButton toastOptions={{ className: 'text-[13.5px]' }} />
    </QueryClientProvider>
  </StrictMode>,
)
