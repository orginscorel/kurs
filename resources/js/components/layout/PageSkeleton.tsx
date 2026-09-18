import { Skeleton } from '@/components/ui/feedback'
import { Mascot } from '@/components/app/Mascot'

export function PageSkeleton() {
  return (
    <div className="animate-fade-in">
      <div className="mb-5 flex items-center gap-3">
        <Mascot size={44} state="loading" />
        <div className="min-w-0 flex-1">
          <Skeleton className="h-7 w-56 mb-2" />
          <Skeleton className="h-4 w-80" />
        </div>
      </div>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-[92px] rounded-[var(--radius-lg)]" />
        ))}
      </div>
      <Skeleton className="h-80 rounded-[var(--radius-lg)]" />
    </div>
  )
}
