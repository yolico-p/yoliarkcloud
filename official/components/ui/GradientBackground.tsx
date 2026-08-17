import { ReactNode } from 'react'

interface GradientBackgroundProps {
  children: ReactNode
  className?: string
}

export function GradientBackground({ children, className = '' }: GradientBackgroundProps) {
  return (
    <div className={`relative overflow-hidden ${className}`}>
      <div className="absolute inset-0 bg-hero-gradient bg-[length:200%_200%] animate-gradient opacity-60" />
      <div className="absolute -left-20 top-20 h-72 w-72 rounded-full bg-blue-200/30 blur-3xl" />
      <div className="absolute -right-20 bottom-20 h-72 w-72 rounded-full bg-violet-200/30 blur-3xl" />
      <div className="relative">{children}</div>
    </div>
  )
}
