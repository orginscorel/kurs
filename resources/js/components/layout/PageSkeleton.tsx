import { Skeleton } from '@/components/ui/feedback'

export function PageSkeleton() {
  return (
    <div className="animate-fade-in">
      <Skeleton className="h-7 w-56 mb-2" />
      <Skeleton className="h-4 w-80 mb-7" />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-[92px] rounded-[var(--radius-lg)]" />
        ))}
      </div>
      <Skeleton className="h-80 rounded-[var(--radius-lg)]" />
    </div>
  )
}
