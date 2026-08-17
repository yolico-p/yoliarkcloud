'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ArrowRight, Cloud, Folder, Link2, Search, Shield } from 'lucide-react';
import { GlassCard } from '@/components/ui/GlassCard';
import { useReducedMotion } from '@/hooks/useReducedMotion';

const WORDS = [
  {
    text: '轻量',
    color: 'text-foreground',
    size: 'text-4xl sm:text-5xl',
    position: 'top-[6%] left-[2%] sm:left-[4%]',
    rotation: -7,
    from: { scale: 0.5, rotation: -16, x: -50, y: -30 },
  },
  {
    text: '安全',
    color: 'text-foreground',
    size: 'text-4xl sm:text-5xl',
    position: 'top-[4%] right-[4%] sm:right-[10%]',
    rotation: 6,
    from: { scale: 0.5, rotation: 18, x: 60, y: -20 },
  },
  {
    text: '单用户',
    color: 'text-muted-foreground',
    size: 'text-2xl sm:text-3xl',
    position: 'top-[22%] right-[12%] sm:right-[18%]',
    rotation: 5,
    from: { scale: 0.5, rotation: 14, x: 50, y: -30 },
  },
  {
    text: '智能化',
    color: 'text-primary',
    size: 'text-6xl sm:text-7xl lg:text-8xl',
    position: 'top-[22%] left-[0%] sm:left-[0%]',
    rotation: -2,
    from: { scale: 0.55, rotation: -10, x: -80, y: 40 },
  },
  {
    text: '私有化',
    color: 'text-muted-foreground',
    size: 'text-2xl sm:text-3xl',
    position: 'top-[48%] right-[6%] sm:right-[12%]',
    rotation: -4,
    from: { scale: 0.5, rotation: -12, x: 60, y: 30 },
  },
  {
    text: '个人云存储',
    color: 'text-foreground',
    size: 'text-4xl sm:text-5xl lg:text-6xl',
    position: 'top-[70%] left-[12%] sm:left-[22%]',
    rotation: 3,
    from: { scale: 0.6, rotation: 8, x: 70, y: 50 },
  },
  {
    text: '智能搜索',
    color: 'text-accent-cyan',
    size: 'text-2xl sm:text-3xl',
    position: 'top-[82%] right-[12%] sm:right-[18%]',
    rotation: -3,
    from: { scale: 0.5, rotation: -10, x: -40, y: 40 },
  },
];

export function Hero() {
  const prefersReducedMotion = useReducedMotion();
  const sectionRef = useRef<HTMLElement>(null);
  const titleRef = useRef<HTMLHeadingElement>(null);
  const subtitleRef = useRef<HTMLParagraphElement>(null);
  const ctaRef = useRef<HTMLDivElement>(null);
  const statusRef = useRef<HTMLDivElement>(null);
  const logoRef = useRef<HTMLDivElement>(null);
  const fragmentsRef = useRef<HTMLDivElement[]>([]);
  const wordRefs = useRef<(HTMLSpanElement | null)[]>([]);

  useEffect(() => {
    if (prefersReducedMotion) return;
    if (!sectionRef.current) return;

    const ctx = gsap.context(() => {
      const words = wordRefs.current.filter(Boolean) as HTMLSpanElement[];
      const fragments = fragmentsRef.current.filter(Boolean) as HTMLDivElement[];

      const tl = gsap.timeline({
        defaults: { ease: 'cubic-bezier(0.34, 1.56, 0.64, 1)' },
      });

      tl.fromTo(
        logoRef.current,
        { y: -20, opacity: 0 },
        { y: 0, opacity: 1, duration: 0.7, ease: 'power2.out' }
      )
        .fromTo(
          words,
          {
            opacity: 0,
            scale: (i) => WORDS[i].from.scale,
            rotation: (i) => WORDS[i].from.rotation,
            x: (i) => WORDS[i].from.x,
            y: (i) => WORDS[i].from.y,
          },
          {
            opacity: 1,
            scale: 1,
            rotation: (i) => WORDS[i].rotation,
            x: 0,
            y: 0,
            duration: 1,
            stagger: 0.12,
            ease: 'back.out(1.4)',
          },
          '-=0.4'
        )
        .fromTo(
          subtitleRef.current,
          { x: -40, opacity: 0 },
          { x: 0, opacity: 1, duration: 0.8, ease: 'power3.out' },
          '-=0.7'
        )
        .fromTo(
          ctaRef.current,
          { y: 30, opacity: 0 },
          { y: 0, opacity: 1, duration: 0.7, ease: 'power2.out' },
          '-=0.5'
        )
        .fromTo(
          statusRef.current,
          { y: 20, opacity: 0 },
          { y: 0, opacity: 1, duration: 0.6, ease: 'power2.out' },
          '-=0.5'
        )
        .fromTo(
          fragments,
          {
            opacity: 0,
            scale: 0.6,
            x: (i) => [80, -60, 100, -80, 60][i],
            y: (i) => [-60, 80, 40, -90, 70][i],
            rotation: (i) => [-8, 6, -4, 10, -6][i],
          },
          {
            opacity: 1,
            scale: 1,
            x: 0,
            y: 0,
            rotation: 0,
            duration: 0.9,
            stagger: 0.1,
            ease: 'back.out(1.4)',
          },
          '-=0.8'
        );
    }, sectionRef);

    return () => ctx.revert();
  }, [prefersReducedMotion]);

  const setFragmentRef = (el: HTMLDivElement | null, index: number) => {
    if (el) fragmentsRef.current[index] = el;
  };

  const fadeInitialClass = prefersReducedMotion ? '' : 'hero-fade-initial';
  const wordInitialClass = prefersReducedMotion ? '' : 'hero-fade-initial';

  return (
    <section
      ref={sectionRef}
      className="relative min-h-screen overflow-hidden pt-32 pb-20 lg:pt-44 lg:pb-32"
    >
      {/* Base gradient background */}
      <div className="absolute inset-0 bg-hero-gradient bg-[length:200%_200%] animate-gradient opacity-60" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-white to-transparent" />

      {/* Brand logo — top left */}
      <div
        ref={logoRef}
        className={`absolute left-4 top-5 z-20 flex items-center gap-2 sm:left-6 sm:top-6 ${fadeInitialClass}`}
      >
        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-white shadow-md shadow-primary/20">
          <Cloud className="h-5 w-5" />
        </div>
        <div className="flex flex-col">
          <span className="text-base font-bold leading-tight text-foreground sm:text-lg">
            柚舟Cloud
          </span>
          <span className="text-[10px] leading-tight text-muted sm:text-xs">
            个人网盘
          </span>
        </div>
      </div>

      {/* Background blobs */}
      <div className="absolute inset-0 pointer-events-none">
        <div className="absolute top-[18%] -left-20 h-[28rem] w-[28rem] rounded-full bg-blue-200/30 blur-3xl" />
      </div>
      <div className="absolute inset-0 pointer-events-none">
        <div className="absolute top-[35%] right-[5%] h-80 w-80 rounded-full bg-violet-200/40 blur-3xl" />
      </div>
      <div className="absolute inset-0 pointer-events-none">
        <div className="absolute bottom-[20%] left-[25%] h-64 w-64 rounded-full bg-cyan-200/30 blur-3xl" />
        <div className="absolute top-[15%] right-[30%] h-2 w-32 rotate-45 rounded-full bg-primary/10" />
      </div>

      <div className="section-container relative z-10">
        <div className="grid items-center gap-16 lg:grid-cols-12 lg:gap-8">
          {/* Text composition */}
          <div className="lg:col-span-7">
            <h1
              ref={titleRef}
              className={`font-bold tracking-tighter ${
                prefersReducedMotion
                  ? 'flex flex-col gap-1'
                  : 'relative h-auto sm:h-[300px] lg:h-[340px]'
              }`}
            >
              {prefersReducedMotion ? (
                <>
                  <span className="block text-4xl text-foreground sm:text-5xl lg:text-6xl">
                    轻量、安全、单用户
                  </span>
                  <span className="block text-5xl text-primary sm:text-6xl lg:text-7xl">
                    智能化
                  </span>
                  <span className="block text-4xl text-foreground sm:text-5xl lg:text-6xl">
                    私有化 · 个人云存储
                  </span>
                  <span className="mt-2 block text-xl text-accent-cyan sm:text-2xl">
                    智能搜索
                  </span>
                </>
              ) : (
                <>
                  <span className="block space-y-1 sm:hidden">
                    <span className="block text-3xl text-foreground">
                      轻量、安全、单用户
                    </span>
                    <span className="block text-4xl text-primary">智能化</span>
                    <span className="block text-3xl text-foreground">
                      私有化 · 个人云存储
                    </span>
                    <span className="mt-1 block text-xl text-accent-cyan">
                      智能搜索
                    </span>
                  </span>
                  <span className="hidden sm:block">
                    {WORDS.map((word, i) => (
                      <span
                        key={word.text}
                        ref={(el) => {
                          wordRefs.current[i] = el;
                        }}
                        className={`absolute ${word.position} ${word.size} ${word.color} mix-blend-multiply whitespace-nowrap ${wordInitialClass}`}
                        style={{ transform: `rotate(${word.rotation}deg)` }}
                      >
                        {word.text}
                      </span>
                    ))}
                  </span>
                </>
              )}
            </h1>

            <p
              ref={subtitleRef}
              className={`mt-10 ml-2 max-w-xl text-lg leading-relaxed text-muted sm:ml-10 sm:text-xl ${fadeInitialClass}`}
            >
              柚舟Cloud 专为单用户场景打造。存文件、管文件、找文件，一条链接完成分享，浏览器即客户端。
              <br />
              简单的东西不是最好的，但最好的东西一定是简单的。
            </p>

            <div
              ref={ctaRef}
              className={`mt-10 flex flex-col gap-4 sm:ml-4 sm:flex-row ${fadeInitialClass}`}
            >
              <a href="#download" className="btn-primary group w-full sm:w-auto">
                立即体验
                <ArrowRight className="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1" />
              </a>
              <a
                href="https://pan.yoliark.com/"
                target="_blank"
                rel="noopener noreferrer"
                className="btn-secondary w-full sm:w-auto"
              >
                马上试用
              </a>
            </div>

            <div
              ref={statusRef}
              className={`mt-10 flex flex-wrap items-center gap-4 text-sm text-muted sm:gap-6 ${fadeInitialClass}`}
            >
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-emerald-500" />
                <span>本地优先</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-primary" />
                <span>AGPL-3.0 开源</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-accent-cyan" />
                <span>智能搜索</span>
              </div>
            </div>
          </div>

          {/* Floating glass fragments */}
          <div className="relative hidden h-[560px] lg:col-span-5 lg:block">
            <div
              ref={(el) => setFragmentRef(el, 0)}
              className={`absolute left-[10%] top-[10%] ${fadeInitialClass}`}
            >
              <GlassCard className="w-56 p-4 shadow-xl">
                <div className="mb-3 flex items-center gap-3 border-b border-slate-200 pb-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-white">
                    <Cloud className="h-4 w-4" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-foreground">柚舟Cloud</h3>
                    <p className="text-xs text-muted">已登录 · 32%</p>
                  </div>
                </div>
                <div className="space-y-2">
                  <div className="flex items-center gap-2 rounded-lg bg-slate-50 p-2">
                    <Folder className="h-4 w-4 text-amber-500" />
                    <span className="flex-1 text-xs text-foreground">项目资料</span>
                    <span className="text-[10px] text-muted">2.4 GB</span>
                  </div>
                  <div className="flex items-center gap-2 rounded-lg bg-slate-50 p-2">
                    <Folder className="h-4 w-4 text-amber-500" />
                    <span className="flex-1 text-xs text-foreground">旅行照片</span>
                    <span className="text-[10px] text-muted">1.1 GB</span>
                  </div>
                </div>
              </GlassCard>
            </div>

            <div
              ref={(el) => setFragmentRef(el, 1)}
              className={`absolute -left-4 top-[44%] ${fadeInitialClass}`}
            >
              <GlassCard className="w-44 p-3 shadow-lg" hover={false}>
                <div className="flex items-center gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <Search className="h-4 w-4" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-foreground">智能搜索</p>
                    <p className="text-xs text-muted">已就绪</p>
                  </div>
                </div>
              </GlassCard>
            </div>

            <div
              ref={(el) => setFragmentRef(el, 2)}
              className={`absolute right-[5%] top-[34%] ${fadeInitialClass}`}
            >
              <div className="glass-strong flex items-center gap-2 rounded-full px-4 py-2 shadow-md">
                <Link2 className="h-4 w-4 text-primary" />
                <span className="text-xs font-medium text-foreground">3 条分享链接</span>
              </div>
            </div>

            <div
              ref={(el) => setFragmentRef(el, 3)}
              className={`absolute left-[5%] bottom-[12%] ${fadeInitialClass}`}
            >
              <div className="flex h-16 w-16 flex-col items-center justify-center rounded-2xl bg-white/80 shadow-lg ring-1 ring-slate-200 backdrop-blur-md">
                <Shield className="h-6 w-6 text-primary" />
                <span className="mt-1 text-[9px] font-medium text-muted">安全</span>
              </div>
            </div>

            <div
              ref={(el) => setFragmentRef(el, 4)}
              className={`absolute right-[12%] bottom-[20%] ${fadeInitialClass}`}
            >
              <div className="glass-strong flex items-center gap-2 rounded-xl px-3 py-2 shadow-md">
                <Link2 className="h-4 w-4 text-amber-500" />
                <span className="text-xs font-medium text-foreground">秒级分享</span>
              </div>
            </div>

            {/* Decorative floating geometry */}
            <div className="absolute right-[25%] top-[12%] pointer-events-none">
              <div className="h-20 w-20 rounded-full border border-primary/10" />
            </div>
            <div className="absolute left-[25%] bottom-[30%] pointer-events-none">
              <div className="h-1 w-24 rotate-45 rounded-full bg-gradient-to-r from-primary/20 to-transparent" />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
