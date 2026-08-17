'use client'

import { useEffect, useRef, useState, type ComponentType } from 'react'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import {
  CloudUpload,
  Link2,
  Search,
  Folder,
  FileText,
  Image as ImageIcon,
  Sparkles,
  Clock,
  Star,
  Trash2,
  Settings,
} from 'lucide-react'
import { SectionTitle } from '@/components/ui/SectionTitle'
import { useReducedMotion } from '@/hooks/useReducedMotion'

const sidebarItems = [
  { icon: FileText, label: '全部文件', active: true },
  { icon: Clock, label: '最近访问' },
  { icon: Star, label: '我的收藏' },
  { icon: Link2, label: '我的分享' },
  { icon: Trash2, label: '回收站' },
  { icon: Sparkles, label: '云助手' },
  { icon: Settings, label: '系统设置' },
]

const files = [
  { name: 'README.md', size: '12 KB', icon: FileText, color: 'text-slate-500' },
  { name: 'vacation.jpg', size: '3.4 MB', icon: ImageIcon, color: 'text-emerald-500' },
  { name: 'project_v2.zip', size: '128 MB', icon: Folder, color: 'text-amber-500' },
  { name: 'meeting_notes.pdf', size: '856 KB', icon: FileText, color: 'text-red-500' },
]

interface Feature {
  id: string
  title: string
  description: string
  icon: ComponentType<{ className?: string }>
  gradient: string
  glowColor: string
  from: { x: number; y: number }
  positionClasses: string
}

const features: Feature[] = [
  {
    id: 'upload',
    title: '拖拽上传',
    description: '拖入即传，进度可见',
    icon: CloudUpload,
    gradient: 'linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)',
    glowColor: 'rgba(37, 99, 235, 0.55)',
    from: { x: -260, y: -220 },
    positionClasses:
      'bottom-[5%] left-[18%] sm:bottom-auto sm:top-[18%] sm:left-[14%]',
  },
  {
    id: 'share',
    title: '秒级分享',
    description: '加密链接，一键分发',
    icon: Link2,
    gradient: 'linear-gradient(135deg, #06b6d4 0%, #0891b2 100%)',
    glowColor: 'rgba(8, 145, 178, 0.55)',
    from: { x: 260, y: -200 },
    positionClasses:
      'bottom-[5%] left-[48%] sm:bottom-auto sm:top-[14%] sm:left-auto sm:right-[10%]',
  },
  {
    id: 'search',
    title: '智能搜索',
    description: '语义搜索，快速定位',
    icon: Search,
    gradient: 'linear-gradient(135deg, #4f46e5 0%, #4338ca 100%)',
    glowColor: 'rgba(79, 70, 229, 0.55)',
    from: { x: 240, y: 220 },
    positionClasses:
      'bottom-[5%] left-[78%] sm:bottom-[16%] sm:left-auto sm:right-[10%]',
  },
]

function MainInterface() {
  return (
    <div className="relative w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
      <div className="flex h-[400px] sm:h-[460px]">
        {/* Sidebar */}
        <div className="hidden w-48 border-r border-slate-200 bg-slate-50 p-4 sm:block">
          <div className="mb-6 flex items-center gap-2 px-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-white">
              <Sparkles className="h-4 w-4" />
            </div>
            <span className="font-semibold text-foreground">柚舟Cloud</span>
          </div>
          <nav className="space-y-1">
            {sidebarItems.map((item) => (
              <div
                key={item.label}
                className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm ${
                  item.active
                    ? 'bg-primary/10 font-medium text-primary'
                    : 'text-muted hover:bg-slate-100'
                }`}
              >
                <item.icon className="h-4 w-4" />
                {item.label}
              </div>
            ))}
          </nav>
        </div>

        {/* Main content */}
        <div className="flex flex-1 flex-col p-4 sm:p-6">
          <div className="mb-4 flex items-center justify-between gap-4">
            <h3 className="text-base font-semibold text-foreground sm:text-lg">
              全部文件
            </h3>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
              <input
                type="text"
                placeholder="搜索文件..."
                className="w-40 rounded-lg bg-slate-100 py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-primary/20 sm:w-64"
                readOnly
              />
            </div>
          </div>

          <div className="flex-1 overflow-hidden rounded-xl border border-slate-200">
            <div className="grid grid-cols-12 gap-4 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium text-muted">
              <div className="col-span-6 sm:col-span-7">名称</div>
              <div className="col-span-3 sm:col-span-2">大小</div>
              <div className="col-span-3 sm:col-span-3">修改时间</div>
            </div>
            {files.map((file) => (
              <div
                key={file.name}
                className="grid grid-cols-12 gap-4 border-b border-slate-100 px-4 py-3 text-sm last:border-0 hover:bg-slate-50"
              >
                <div className="col-span-6 flex items-center gap-2 sm:col-span-7">
                  <file.icon className={`h-4 w-4 ${file.color}`} />
                  <span className="truncate text-foreground">{file.name}</span>
                </div>
                <div className="col-span-3 text-muted sm:col-span-2">
                  {file.size}
                </div>
                <div className="col-span-3 text-muted sm:col-span-3">
                  2026-08-05
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

    </div>
  )
}

export function Features() {
  const sectionRef = useRef<HTMLElement>(null)
  const titleRef = useRef<HTMLDivElement>(null)
  const stageRef = useRef<HTMLDivElement>(null)
  const mockupRef = useRef<HTMLDivElement>(null)
  const nodeRefs = useRef<(HTMLDivElement | null)[]>([])

  const prefersReducedMotion = useReducedMotion()
  const [activeIndex, setActiveIndex] = useState<number | null>(null)
  const hoveredRef = useRef(false)
  const scrollActiveRef = useRef<number | null>(null)

  useEffect(() => {
    if (prefersReducedMotion) return
    if (
      !sectionRef.current ||
      !stageRef.current ||
      !mockupRef.current ||
      !titleRef.current
    )
      return

    gsap.registerPlugin(ScrollTrigger)

    const isDesktop = window.innerWidth >= 768
    if (!isDesktop) return

    const ctx = gsap.context(() => {
      const nodes = nodeRefs.current.filter(Boolean) as HTMLDivElement[]

      gsap.set(mockupRef.current, {
        xPercent: -50,
        yPercent: -50,
        left: '50%',
        top: '50%',
      })
      gsap.set(nodes, { xPercent: -50, yPercent: -50 })

      const updateActive = (idx: number | null) => {
        scrollActiveRef.current = idx
        if (!hoveredRef.current) {
          setActiveIndex(idx)
        }
      }

      const tl = gsap.timeline({
        scrollTrigger: {
          trigger: sectionRef.current,
          start: 'top top',
          end: '+=145%',
          pin: true,
          scrub: 0.6,
        },
      })

      tl.fromTo(
        titleRef.current,
        { opacity: 0, y: 40 },
        { opacity: 1, y: 0, duration: 0.18, ease: 'power2.out' },
        0
      )

      tl.fromTo(
        mockupRef.current,
        { rotationX: 16, rotationY: -20, scale: 0.82, opacity: 0.5 },
        { rotationX: 0, rotationY: 0, scale: 1, opacity: 1, duration: 0.5, ease: 'none' },
        0
      )

      tl.fromTo(
        nodes,
        {
          x: (i) => features[i].from.x,
          y: (i) => features[i].from.y,
          scale: 0.5,
          opacity: 0,
        },
        {
          x: 0,
          y: 0,
          scale: 1,
          opacity: 1,
          duration: 0.55,
          stagger: 0.07,
          ease: 'none',
        },
        0.1
      )

      tl.addLabel('feature-upload', 0.55)
      tl.call(() => updateActive(0), [], 'feature-upload')

      tl.addLabel('feature-share', 0.72)
      tl.call(() => updateActive(1), [], 'feature-share')

      tl.addLabel('feature-search', 0.88)
      tl.call(() => updateActive(2), [], 'feature-search')

      tl.call(() => updateActive(null), [], 0.98)
    }, sectionRef)

    return () => ctx.revert()
  }, [prefersReducedMotion])

  return (
    <section
      id="features"
      ref={sectionRef}
      className="relative overflow-hidden bg-gradient-to-b from-white via-slate-50/90 to-white py-20 lg:py-28"
    >
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-[#faf5ff] to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-surface to-transparent" />

      {/* Ambient background with strong blur */}
      <div
        className="pointer-events-none absolute inset-0 z-0"
        aria-hidden="true"
        style={{
          background:
            'radial-gradient(ellipse at 20% 15%, rgba(224,242,254,0.55), transparent 45%), radial-gradient(ellipse at 80% 85%, rgba(207,250,254,0.5), transparent 45%), radial-gradient(ellipse at 50% 50%, rgba(238,242,255,0.4), transparent 60%)',
        }}
      />
      <div
        className="pointer-events-none absolute -left-40 top-16 h-96 w-96 rounded-full opacity-50 blur-3xl"
        style={{ background: 'rgba(37, 99, 235, 0.22)' }}
      />
      <div
        className="pointer-events-none absolute -right-48 bottom-12 h-[28rem] w-[28rem] rounded-full opacity-50 blur-3xl"
        style={{ background: 'rgba(8, 145, 178, 0.2)' }}
      />
      <div
        className="pointer-events-none absolute left-1/3 top-1/2 h-80 w-80 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-40 blur-3xl"
        style={{ background: 'rgba(99, 102, 241, 0.15)' }}
      />

      {/* Title */}
      <div className="section-container relative z-10">
        <div ref={titleRef}>
          <SectionTitle
            eyebrow="核心功能"
            title="一个人用，刚刚好"
            description="没有复杂的企业级功能，只保留你最需要的个人云存储能力。"
          />
        </div>
      </div>

      {/* Stage */}
      <div
        ref={stageRef}
        className="relative z-10 mx-auto mt-14 hidden h-[760px] w-full max-w-7xl md:block"
        style={{ perspective: '1400px' }}
      >
        {/* Central interface mockup */}
        <div
          ref={mockupRef}
          className="absolute top-1/2 left-1/2 w-[min(94vw,900px)] -translate-x-1/2 -translate-y-1/2 will-change-transform"
        >
          <MainInterface />
        </div>

        {/* Orbiting feature nodes */}
        {features.map((feature, i) => {
          const active = activeIndex === i || prefersReducedMotion
          return (
            <div
              key={feature.id}
              ref={(el) => {
                nodeRefs.current[i] = el
              }}
              className={`absolute ${feature.positionClasses} z-10 flex flex-col items-center will-change-transform`}
              onMouseEnter={() => {
                hoveredRef.current = true
                setActiveIndex(i)
              }}
              onMouseLeave={() => {
                hoveredRef.current = false
                setActiveIndex(scrollActiveRef.current)
              }}
            >
              <div className="group relative">
                {/* Outer glow */}
                <div
                  className={`pointer-events-none absolute -inset-4 rounded-full transition-opacity duration-500 ${
                    active ? 'opacity-100' : 'opacity-0'
                  }`}
                  style={{
                    background: `radial-gradient(circle, ${feature.glowColor} 0%, transparent 70%)`,
                    filter: 'blur(10px)',
                  }}
                />

                {/* Orb */}
                <div
                  className="relative flex h-[72px] w-[72px] items-center justify-center rounded-full shadow-xl transition-transform duration-300 group-hover:scale-110 sm:h-20 sm:w-20"
                  style={{
                    background: feature.gradient,
                    boxShadow: `0 0 44px -10px ${feature.glowColor}`,
                  }}
                >
                  <feature.icon className="h-6 w-6 text-white sm:h-7 sm:w-7" />
                  <div
                    className={`pointer-events-none absolute inset-0 rounded-full ring-2 ring-white/40 transition-opacity duration-500 ${
                      active ? 'opacity-100' : 'opacity-0'
                    }`}
                  />
                </div>
              </div>

              {/* Label */}
              <div className="mt-3 text-center">
                <p className="text-sm font-semibold text-foreground">
                  {feature.title}
                </p>
                <p className="max-w-[120px] text-xs leading-relaxed text-muted">
                  {feature.description}
                </p>
              </div>
            </div>
          )
        })}
      </div>

      {/* Mobile stacked layout */}
      <div className="section-container relative z-10 mt-12 md:hidden">
        <div className="mx-auto max-w-3xl">
          <MainInterface />
        </div>
        <div className="mt-10 grid gap-6 sm:grid-cols-3">
          {features.map((feature) => (
            <div
              key={feature.id}
              className="group flex flex-col items-center text-center"
            >
              <div
                className="relative flex h-16 w-16 items-center justify-center rounded-full shadow-xl transition-transform duration-300 group-hover:scale-110 sm:h-20 sm:w-20"
                style={{
                  background: feature.gradient,
                  boxShadow: `0 0 44px -10px ${feature.glowColor}`,
                }}
              >
                <feature.icon className="h-7 w-7 text-white sm:h-8 sm:w-8" />
              </div>
              <div className="mt-4">
                <p className="text-base font-semibold text-foreground">
                  {feature.title}
                </p>
                <p className="mt-1 max-w-[180px] text-sm leading-relaxed text-muted">
                  {feature.description}
                </p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
