interface SectionTitleProps {
  eyebrow?: string
  title: string
  description?: string
  align?: 'left' | 'center'
}

export function SectionTitle({ eyebrow, title, description, align = 'center' }: SectionTitleProps) {
  return (
    <div className={`max-w-3xl ${align === 'center' ? 'mx-auto text-center' : ''}`}>
      {eyebrow && (
        <span className="mb-3 inline-block rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary">
          {eyebrow}
        </span>
      )}
      <h2 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">{title}</h2>
      {description && (
        <p className="mt-4 text-lg text-muted">{description}</p>
      )}
    </div>
  )
}
