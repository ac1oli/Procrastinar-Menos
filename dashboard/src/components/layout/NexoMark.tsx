import { cn } from '@/lib/utils'

/** Marca do NEXO: um "N" que liga dois pontos — rotina, objetivos e memória conectados. */
export function NexoMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true" className={cn('size-8 shrink-0', className)}>
      <defs>
        <linearGradient id="nexo-mark" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
          <stop stopColor="#5146d9" />
          <stop offset="0.6" stopColor="#2f8fd6" />
          <stop offset="1" stopColor="#3fc6c9" />
        </linearGradient>
      </defs>
      <rect width="32" height="32" rx="9" fill="url(#nexo-mark)" />
      <path d="M9.5 22.5V9.5l13 13V9.5" stroke="#fff" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="9.5" cy="9.5" r="2.4" fill="#fff" />
      <circle cx="22.5" cy="22.5" r="2.4" fill="#fff" />
    </svg>
  )
}

export function NexoWordmark({ className }: { className?: string }) {
  return (
    <span className={cn('flex items-center gap-2.5', className)}>
      <NexoMark />
      <span className="leading-tight">
        <span className="block text-base font-semibold tracking-tight">NEXO</span>
        <span className="block text-xs text-muted-foreground">Procrastinar Menos</span>
      </span>
    </span>
  )
}
