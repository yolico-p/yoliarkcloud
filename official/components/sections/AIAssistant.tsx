'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { Sparkles, Wand2, Copy, PieChart, Weight } from 'lucide-react';
import { LiquidBlob } from '@/components/ui/LiquidBlob';
import { useReducedMotion } from '@/hooks/useReducedMotion';

const messages = [
  { type: 'user' as const, text: '帮我整理一下当前目录的文件' },
  {
    type: 'ai' as const,
    text: '已按类型为你创建「文档」「图片」「视频」「压缩包」四个文件夹，并将 24 个文件分别移入对应目录。',
  },
  { type: 'user' as const, text: '哪些文件占空间最大？' },
  {
    type: 'ai' as const,
    text: '已列出前 10 个大文件，其中「backup.zip」占 4.2 GB，建议优先清理。',
  },
];

const actions = [
  { icon: Wand2, label: '智能整理', description: '按类型自动分类当前目录文件' },
  { icon: Copy, label: '查重清理', description: '快速找出重复文件' },
  { icon: PieChart, label: '空间分析', description: '分析占用并提供清理建议' },
  { icon: Weight, label: '大文件清理', description: '列出占用空间最大的文件' },
];

function bubbleBorderRadius(type: 'user' | 'ai') {
  return type === 'user' ? '24px 24px 8px 24px' : '24px 24px 24px 8px';
}

export function AIAssistant() {
  const sectionRef = useRef<HTMLElement>(null);
  const prefersReducedMotion = useReducedMotion();

  useEffect(() => {
    if (prefersReducedMotion || !sectionRef.current) return;

    gsap.registerPlugin(ScrollTrigger);

    const ctx = gsap.context(() => {
      const tl = gsap.timeline({
        scrollTrigger: {
          trigger: sectionRef.current,
          start: 'top 75%',
          once: true,
        },
      });

      tl.fromTo(
        '.ai-phone',
        { y: 50, opacity: 0, scale: 0.96 },
        { y: 0, opacity: 1, scale: 1, duration: 1, ease: 'power3.out' }
      )
        .fromTo(
          '.ai-message-bubble',
          { y: 24, opacity: 0, scale: 0.92 },
          {
            y: 0,
            opacity: 1,
            scale: 1,
            duration: 0.75,
            stagger: 0.12,
            ease: 'back.out(1.4)',
          },
          '-=0.65'
        )
        .fromTo(
          '.ai-title, .ai-description',
          { x: 36, opacity: 0 },
          {
            x: 0,
            opacity: 1,
            duration: 0.8,
            stagger: 0.1,
            ease: 'power2.out',
          },
          '-=0.5'
        )
        .fromTo(
          '.ai-action',
          { x: 36, opacity: 0 },
          {
            x: 0,
            opacity: 1,
            duration: 0.7,
            stagger: 0.08,
            ease: 'power2.out',
          },
          '-=0.5'
        );
    }, sectionRef);

    return () => ctx.revert();
  }, [prefersReducedMotion]);

  const hiddenClass = prefersReducedMotion ? '' : 'opacity-0';

  return (
    <section id="ai" ref={sectionRef} className="relative overflow-hidden bg-surface py-24 lg:py-32">
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-white to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-white to-transparent" />

      {/* 背景液态光球 —— 低饱和度、轻量模糊，仅作氛围 */}
      <div className="pointer-events-none absolute inset-0 overflow-hidden">
        <LiquidBlob
          size={320}
          color="blue"
          speed={18}
          className="absolute -right-16 top-1/4 opacity-40 blur-xl saturate-50 md:blur-2xl"
        />
        <LiquidBlob
          size={240}
          color="purple"
          speed={14}
          className="absolute -left-12 bottom-1/4 opacity-30 blur-lg saturate-50 md:blur-xl"
        />
      </div>

      <div className="section-container relative z-10">
        <div className="mx-auto grid max-w-6xl items-center gap-10 lg:grid-cols-2 lg:gap-10">
          {/* 左侧：手机聊天界面 mockup */}
          <div className="ai-phone relative mx-auto w-full max-w-md lg:mx-0">
            <div className="liquid-glass relative overflow-hidden rounded-[2.5rem] border border-white/20 p-5 shadow-2xl">
              {/* 状态栏 */}
              <div className="mb-5 flex items-center justify-between px-2">
                <span className="text-xs font-medium text-muted">云助手</span>
                <div className="flex items-center gap-1.5">
                  <div className="h-2 w-2 rounded-full bg-primary/70" />
                  <div className="h-2 w-2 rounded-full bg-primary/45" />
                  <div className="h-2 w-2 rounded-full bg-primary/20" />
                </div>
              </div>

              {/* 对话气泡 */}
              <div className="space-y-4">
                {messages.map((msg, index) => (
                  <div
                    key={index}
                    className={`ai-message-bubble flex items-end gap-2 ${
                      msg.type === 'user' ? 'flex-row-reverse' : 'flex-row'
                    } ${hiddenClass}`}
                  >
                    <div
                      className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${
                        msg.type === 'user' ? 'bg-slate-200 text-foreground' : 'bg-primary text-white'
                      }`}
                    >
                      {msg.type === 'user' ? (
                        <span className="text-[10px] font-medium">我</span>
                      ) : (
                        <Sparkles className="h-3.5 w-3.5" />
                      )}
                    </div>
                    <div
                      className="liquid-glass max-w-[78%] px-3.5 py-2 text-sm leading-relaxed text-foreground"
                      style={{ borderRadius: bubbleBorderRadius(msg.type) }}
                    >
                      {msg.text}
                    </div>
                  </div>
                ))}
              </div>

              {/* 底部输入框占位 */}
              <div className="mt-5 flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2">
                <div className="h-3 flex-1 rounded-full bg-white/10" />
                <div className="h-6 w-6 rounded-full bg-primary/20" />
              </div>
            </div>
          </div>

          {/* 右侧：标题、描述与动作卡片 */}
          <div className="ai-features flex flex-col justify-center">
            <div className="mb-8 lg:mb-10">
              <h2 className={`ai-title text-3xl font-bold tracking-tight text-foreground sm:text-4xl ${hiddenClass}`}>
                让文件管理更聪明
              </h2>
              <p className={`ai-description mt-4 text-base leading-relaxed text-muted sm:text-lg ${hiddenClass}`}>
                像聊天一样描述需求，智能整理、查重清理、空间分析、大文件清理，一句话搞定。
              </p>
            </div>

            <div className="ai-actions flex flex-col gap-3 sm:gap-4">
              {actions.map((action) => (
                <div
                  key={action.label}
                  className={`ai-action liquid-glass flex items-center gap-4 rounded-xl p-4 transition-transform duration-300 hover:-translate-y-0.5 ${hiddenClass}`}
                >
                  <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <action.icon className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <h4 className="text-base font-semibold text-foreground">{action.label}</h4>
                    <p className="text-sm text-muted">{action.description}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
