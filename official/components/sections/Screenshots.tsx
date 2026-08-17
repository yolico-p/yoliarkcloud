'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { Folder, Search, Star, Link2, Trash2, Clock, Settings, Sparkles, Share2, Bot } from 'lucide-react';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { useReducedMotion } from '@/hooks/useReducedMotion';

const sidebarItems = [
  { icon: Folder, label: '全部文件', active: true },
  { icon: Clock, label: '最近访问' },
  { icon: Star, label: '我的收藏' },
  { icon: Link2, label: '我的分享' },
  { icon: Trash2, label: '回收站' },
  { icon: Sparkles, label: '云助手' },
  { icon: Settings, label: '系统设置' },
];

const files = [
  { name: 'README.md', size: '12 KB', type: 'doc' },
  { name: 'vacation.jpg', size: '3.4 MB', type: 'image' },
  { name: 'project_v2.zip', size: '128 MB', type: 'zip' },
  { name: 'meeting_notes.pdf', size: '856 KB', type: 'pdf' },
];

interface FileManagerCardProps {
  variant?: 'files' | 'shares' | 'ai';
}

function FileManagerCard({ variant = 'files' }: FileManagerCardProps) {
  const isShares = variant === 'shares';
  const isAi = variant === 'ai';

  return (
    <div className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
      <div className="flex h-[420px] flex-col sm:h-[480px] sm:flex-row">
        {/* Sidebar */}
        <div className="hidden w-52 border-r border-slate-200 bg-slate-50 p-4 sm:block">
          <div className="mb-6 flex items-center gap-2 px-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-white">
              <Sparkles className="h-4 w-4" />
            </div>
            <span className="font-semibold text-foreground">柚舟Cloud</span>
          </div>
          <nav className="space-y-1">
            {sidebarItems.map((item) => {
              const active = isShares ? item.label === '我的分享' : isAi ? item.label === '云助手' : item.active;
              return (
                <div
                  key={item.label}
                  className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm ${
                    active ? 'bg-primary/10 font-medium text-primary' : 'text-muted hover:bg-slate-100'
                  }`}
                >
                  <item.icon className="h-4 w-4" />
                  {item.label}
                </div>
              );
            })}
          </nav>
        </div>

        {/* Main content */}
        <div className="flex-1 p-4 sm:p-6">
          <div className="mb-4 flex items-center justify-between sm:mb-6">
            <h3 className="text-base font-semibold text-foreground sm:text-lg">
              {isShares ? '我的分享' : isAi ? '云助手' : '全部文件'}
            </h3>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
              <input
                type="text"
                placeholder="搜索文件..."
                className="w-40 rounded-lg bg-slate-100 py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-primary/20 sm:w-64"
              />
            </div>
          </div>

          {isAi ? (
            <div className="flex h-[calc(100%-56px)] flex-col rounded-xl border border-slate-200 bg-slate-50/50 p-4">
              <div className="mb-3 flex items-center gap-2 text-sm text-muted">
                <Bot className="h-4 w-4 text-primary" />
                <span>云助手 · 在线</span>
              </div>
              <div className="flex-1 space-y-3 overflow-hidden">
                <div className="w-fit max-w-[80%] rounded-2xl rounded-tl-none bg-slate-100 px-4 py-2 text-sm text-foreground">
                  帮我查一下有没有重复文件
                </div>
                <div className="ml-auto w-fit max-w-[80%] rounded-2xl rounded-tr-none bg-primary px-4 py-2 text-sm text-white">
                  已找到 3 组重复文件，共可释放 1.2 GB 空间，是否一键清理？
                </div>
                <div className="w-fit max-w-[80%] rounded-2xl rounded-tl-none bg-slate-100 px-4 py-2 text-sm text-foreground">
                  分析一下我的存储占用
                </div>
                <div className="ml-auto w-fit max-w-[80%] rounded-2xl rounded-tr-none bg-primary px-4 py-2 text-sm text-white">
                  图片 45%、视频 30%、文档 15%、其他 10%，建议优先清理视频缓存。
                </div>
              </div>
            </div>
          ) : (
            <div className="rounded-xl border border-slate-200">
              <div className="grid grid-cols-12 gap-4 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium text-muted">
                <div className="col-span-6 sm:col-span-7">名称</div>
                <div className="col-span-3 sm:col-span-2">大小</div>
                <div className="col-span-3 sm:col-span-3">修改时间</div>
              </div>
              {files.map((file, index) => (
                <div
                  key={index}
                  className="grid grid-cols-12 gap-4 border-b border-slate-100 px-4 py-3 text-sm last:border-0 hover:bg-slate-50"
                >
                  <div className="col-span-6 flex items-center gap-2 sm:col-span-7">
                    {isShares ? (
                      <Share2 className="h-4 w-4 text-emerald-500" />
                    ) : (
                      <Folder className="h-4 w-4 text-amber-500" />
                    )}
                    <span className="truncate text-foreground">{file.name}</span>
                  </div>
                  <div className="col-span-3 text-muted sm:col-span-2">{file.size}</div>
                  <div className="col-span-3 text-muted sm:col-span-3">2026-08-05</div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

export function Screenshots() {
  const sectionRef = useRef<HTMLElement>(null);
  const stageRef = useRef<HTMLDivElement>(null);
  const cardsRef = useRef<HTMLDivElement[]>([]);
  const prefersReducedMotion = useReducedMotion();

  useEffect(() => {
    if (prefersReducedMotion) return;
    if (!sectionRef.current || !stageRef.current) return;

    gsap.registerPlugin(ScrollTrigger);

    const isDesktop = window.innerWidth >= 768;
    if (!isDesktop) return;

    const cards = cardsRef.current;

    gsap.set(cards, {
      xPercent: -50,
      yPercent: -50,
      left: '50%',
      top: '50%',
      x: 0,
      y: 0,
      rotationX: 0,
      rotationY: 0,
      rotationZ: 0,
      scale: 1,
      opacity: 1,
    });

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: sectionRef.current,
        start: 'top top',
        end: '+=130%',
        pin: true,
        scrub: 0.6,
      },
    });

    tl.fromTo(
      cards,
      {
        x: 0,
        y: 0,
        rotationX: 12,
        rotationY: -28,
        rotationZ: -6,
        scale: 0.82,
        opacity: 0.65,
      },
      {
        x: (i) => (i - 1) * 160,
        y: (i) => (i === 1 ? 0 : i === 0 ? 24 : -24),
        rotationX: 0,
        rotationY: (i) => (i - 1) * -8,
        rotationZ: (i) => (i - 1) * 3,
        scale: 1,
        opacity: 1,
        stagger: 0.08,
        duration: 1,
        ease: 'none',
      },
      0
    );

    return () => {
      tl.scrollTrigger?.kill();
      tl.kill();
    };
  }, [prefersReducedMotion]);

  return (
    <section id="screenshots" ref={sectionRef} className="relative overflow-hidden bg-white py-24">
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-slate-50 to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-surface to-transparent" />

      <div className="section-container">
        <div className="pt-8 sm:pt-16">
          <SectionTitle
            eyebrow="界面预览"
            title="干净直观的文件管理"
            description="熟悉的操作逻辑，清晰的视觉层次，文件管理从未如此轻松。"
          />
        </div>
      </div>

      {prefersReducedMotion ? (
        <div className="section-container mt-16">
          <div className="grid gap-6 md:grid-cols-3">
            <FileManagerCard variant="shares" />
            <FileManagerCard variant="files" />
            <FileManagerCard variant="ai" />
          </div>
        </div>
      ) : (
        <>
          <div
            ref={stageRef}
            className="relative mx-auto mt-16 hidden h-[560px] w-full max-w-7xl md:block"
            style={{ perspective: '1200px' }}
          >
            <div
              ref={(el) => {
                if (el) cardsRef.current[0] = el;
              }}
              className="absolute top-1/2 left-1/2 w-[min(80vw,640px)] will-change-transform"
            >
              <FileManagerCard variant="ai" />
            </div>
            <div
              ref={(el) => {
                if (el) cardsRef.current[1] = el;
              }}
              className="absolute top-1/2 left-1/2 w-[min(80vw,640px)] will-change-transform"
            >
              <FileManagerCard variant="files" />
            </div>
            <div
              ref={(el) => {
                if (el) cardsRef.current[2] = el;
              }}
              className="absolute top-1/2 left-1/2 w-[min(80vw,640px)] will-change-transform"
            >
              <FileManagerCard variant="shares" />
            </div>
          </div>
          <div className="section-container mt-12 md:hidden">
            <div className="grid gap-6 sm:grid-cols-2">
              <FileManagerCard variant="shares" />
              <FileManagerCard variant="files" />
              <FileManagerCard variant="ai" />
            </div>
          </div>
        </>
      )}
    </section>
  );
}
