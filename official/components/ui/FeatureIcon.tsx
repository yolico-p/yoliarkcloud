import { LucideIcon } from 'lucide-react'

interface FeatureIconProps {
  icon: LucideIcon
  color?: 'primary' | 'cyan' | 'purple'
}

const colorMap = {
  primary: 'bg-primary/10 text-primary',
  cyan: 'bg-cyan-100 text-accent-cyan',
  purple: 'bg-violet-100 text-accent-purple',
}

export function FeatureIcon({ icon: Icon, color = 'primary' }: FeatureIconProps) {
  return (
    <div className={`inline-flex h-12 w-12 items-center justify-center rounded-xl ${colorMap[color]}`}>
      <Icon className="h-6 w-6" />
    </div>
  )
}
