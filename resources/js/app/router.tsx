import type { ReactNode } from 'react'
import { createBrowserRouter, Navigate, Outlet, useLocation, useRouteError } from 'react-router-dom'
import { useAuth } from '@/app/auth'
import { AppShell } from '@/components/layout/AppShell'
import { AuthGate } from '@/app/AuthGate'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import { modules } from '@/app/modules'
import { EmptyState } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { reportClientError } from '@/components/layout/ErrorBoundary'

const Login = lazyPage(() => import('@/pages/auth/Login'))
const NotFound = lazyPage(() => import('@/pages/NotFound'))

function RouteError() {
  const error = useRouteError()
  console.error(error)
  reportClientError(error, `route:${window.location.pathname}`)
  return (
    <div className="grid min-h-dvh place-items-center bg-bg">
      <EmptyState
        title="Sayfa yüklenirken bir sorun oluştu"
        description="Lütfen sayfayı yenileyin. Sorun sürerse sistem yöneticisine bildirin."
        action={
          <Button variant="primary" onClick={() => window.location.reload()}>
            Sayfayı yenile
          </Button>
        }
      />
    </div>
  )
}

const ownShell = (path: string, base: string) => path === base || path.startsWith(`${base}/`)

/**
 * Kabuk seçimi: /portal (öğrenci/veli) ve /ogretmen (öğretmen portalı) kendi sade kabuklarını kullanır;
 * bu hesaplar yönetim kabuğunu hiç görmez, kendi portallarına yönlenir.
 */
function ShellSwitch({ children }: { children: ReactNode }) {
  const location = useLocation()
  const userType = useAuth((s) => s.me?.user.user_type)
  const portal = useAuth((s) => s.me?.portal)
  if (ownShell(location.pathname, '/portal') || ownShell(location.pathname, '/ogretmen')) return <>{children}</>
  if (userType === 'student' || userType === 'guardian') return <Navigate to="/portal" replace />
  if (portal === 'teacher') return <Navigate to="/ogretmen" replace />
  return <AppShell>{children}</AppShell>
}

export const router = createBrowserRouter([
  {
    errorElement: <RouteError />,
    children: [
      { path: '/giris', element: page(<Login />) },
      {
        element: (
          <AuthGate>
            <ShellSwitch>
              <Outlet />
            </ShellSwitch>
          </AuthGate>
        ),
        errorElement: <RouteError />,
        children: [...modules.flatMap((m) => m.routes), { path: '*', element: page(<NotFound />) }],
      },
    ],
  },
])
