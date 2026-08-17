'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import {
  ShieldCheck,
  Lock,
  Fingerprint,
  Eye,
  Server,
} from 'lucide-react';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { useReducedMotion } from '@/hooks/useReducedMotion';

const SECURITY_FEATURES = [
  { icon: Lock, label: 'AES-256 加密', description: '静态与传输全程加密' },
  { icon: Fingerprint, label: '身份认证', description: '单用户登录验证' },
  { icon: Eye, label: '访问审计', description: '完整操作日志追踪' },
  { icon: Server, label: '本地优先', description: '数据自主可控' },
];

export function Security() {
  const sectionRef = useRef<HTMLElement>(null);
  const prefersReducedMotion = useReducedMotion();

  const shieldRef = useRef<HTMLDivElement>(null);
  const ringRefs = useRef<(HTMLDivElement | null)[]>([]);
  const nodeRefs = useRef<(HTMLDivElement | null)[]>([]);
  const lineRefs = useRef<(HTMLDivElement | null)[]>([]);
  const labelRefs = useRef<(HTMLDivElement | null)[]>([]);
  const scoreRef = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    if (prefersReducedMotion || !sectionRef.current) return;

    gsap.registerPlugin(ScrollTrigger);

    const ctx = gsap.context(() => {
      const tl = gsap.timeline({
        scrollTrigger: {
          trigger: sectionRef.current,
          start: 'top 70%',
          toggleActions: 'play none none reverse',
        },
        defaults: { ease: 'power3.out' },
      });

      // 盾牌核心从虚空中凝聚
      tl.fromTo(
        shieldRef.current,
        { scale: 0.4, opacity: 0, rotate: -12 },
        { scale: 1, opacity: 1, rotate: 0, duration: 1, ease: 'back.out(1.4)' }
      );

      // 光环层层扩散
      tl.fromTo(
        ringRefs.current.filter(Boolean),
        { scale: 0.6, opacity: 0 },
        { scale: 1, opacity: 1, duration: 0.9, stagger: 0.12, ease: 'power2.out' },
        0.2
      );

      // 安全评分数字计数
      if (scoreRef.current) {
        tl.fromTo(
          scoreRef.current,
          { textContent: '0' },
          { textContent: 97, duration: 1.2, snap: { textContent: 1 }, ease: 'power2.out' },
          0.4
        );
      }

      // 连接线从盾牌向外延伸
      tl.fromTo(
        lineRefs.current.filter(Boolean),
        { scaleY: 0, opacity: 0 },
        { scaleY: 1, opacity: 1, duration: 0.6, stagger: 0.1, ease: 'power2.out' },
        0.6
      );

      // 特性节点依次亮起
      tl.fromTo(
        nodeRefs.current.filter(Boolean),
        { scale: 0.6, opacity: 0, y: 20 },
        { scale: 1, opacity: 1, y: 0, duration: 0.7, stagger: 0.12, ease: 'back.out(1.5)' },
        0.8
      );

      // 标签文字淡入
      tl.fromTo(
        labelRefs.current.filter(Boolean),
        { opacity: 0, y: 10 },
        { opacity: 1, y: 0, duration: 0.5, stagger: 0.1, ease: 'power2.out' },
        1
      );


    }, sectionRef);

    return () => ctx.revert();
  }, [prefersReducedMotion]);

  const hiddenClass = prefersReducedMotion ? '' : 'opacity-0';

  return (
    <section
      id="security"
      ref={sectionRef}
      className="relative overflow-hidden bg-white py-24 lg:py-32"
    >
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-surface to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-white to-transparent" />

      {/* 深层光晕背景 */}
      <div className="pointer-events-none absolute inset-0 z-0">
        <div className="absolute left-1/2 top-1/2 h-[600px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/[0.03] blur-3xl" />
        <div className="absolute left-1/2 top-1/2 h-[400px] w-[400px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-emerald-500/[0.04] blur-2xl" />
      </div>

      <div className="section-container relative z-10">
        <div className="pt-8 sm:pt-16">
          <SectionTitle
            eyebrow="安全隐私"
            title="你的数据，你做主"
            description="从加密到审计，每一层都为单用户场景的安全稳定而设计。"
          />
        </div>

        <div className="relative mx-auto mt-16 max-w-4xl">
          {/* 移动端：垂直堆叠 */}
          <div className="grid gap-6 md:hidden">
            <div
              ref={shieldRef}
              className={`relative mx-auto flex h-44 w-44 items-center justify-center ${hiddenClass}`}
            >
              <div className="absolute inset-0 rounded-full bg-primary/5" />
              <div className="absolute inset-3 rounded-full bg-primary/10" />
              <div className="absolute inset-6 rounded-full bg-primary/10" />
              <div className="relative flex h-24 w-24 flex-col items-center justify-center rounded-3xl bg-gradient-to-br from-primary to-primary-dark text-white shadow-2xl shadow-primary/30">
                <ShieldCheck className="h-10 w-10" />
                <span ref={scoreRef} className="mt-1 text-2xl font-bold">97</span>
              </div>
            </div>

            {SECURITY_FEATURES.map((feature, index) => (
              <div
                key={feature.label}
                ref={(el) => { nodeRefs.current[index] = el; }}
                className={`flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ${hiddenClass}`}
              >
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                  <feature.icon className="h-6 w-6" />
                </div>
                <div>
                  <div className="font-semibold text-foreground">{feature.label}</div>
                  <div className="text-sm text-muted">{feature.description}</div>
                </div>
              </div>
            ))}
          </div>

          {/* 桌面端：中心盾牌 + 四角辐射 */}
          <div className="relative hidden h-[560px] md:block">
            {/* 同心护盾光环 */}
            {[
              'h-[480px] w-[480px] border-slate-200/60',
              'h-[360px] w-[360px] border-primary/10',
              'h-[240px] w-[240px] border-primary/20',
            ].map((cls, index) => (
              <div
                key={index}
                ref={(el) => { ringRefs.current[index] = el; }}
                className={`pointer-events-none absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full border ${cls} ${hiddenClass}`}
              />
            ))}

            {/* 中心盾牌 */}
            <div
              ref={shieldRef}
              className={`absolute left-1/2 top-1/2 z-20 flex h-40 w-40 -translate-x-1/2 -translate-y-1/2 items-center justify-center ${hiddenClass}`}
            >
              <div className="absolute inset-0 animate-pulse rounded-full bg-primary/10 blur-xl" />
              <div className="relative flex h-full w-full flex-col items-center justify-center rounded-[2rem] bg-gradient-to-br from-primary to-primary-dark text-white shadow-2xl shadow-primary/30">
                <ShieldCheck className="h-14 w-14" />
                <span ref={scoreRef} className="mt-1 text-3xl font-bold">97</span>
                <span className="text-[10px] font-medium opacity-80">安全评分</span>
              </div>
            </div>

            {/* 连接线与特性节点 */}
            {SECURITY_FEATURES.map((feature, index) => {
              const positions = [
                { node: 'top-[8%] left-1/2 -translate-x-1/2', line: 'left-1/2 top-[calc(20%+80px)] h-[120px] w-px -translate-x-1/2 origin-top', align: 'items-center text-center' },
                { node: 'right-[5%] top-1/2 -translate-y-1/2', line: 'right-[calc(5%+140px)] top-1/2 h-px w-[80px] origin-right', align: 'items-end text-right' },
                { node: 'bottom-[8%] left-1/2 -translate-x-1/2', line: 'left-1/2 bottom-[calc(8%+80px)] h-[120px] w-px -translate-x-1/2 origin-bottom', align: 'items-center text-center' },
                { node: 'left-[5%] top-1/2 -translate-y-1/2', line: 'left-[calc(5%+140px)] top-1/2 h-px w-[80px] origin-left', align: 'items-start text-left' },
              ];
              const pos = positions[index];

              return (
                <div key={feature.label}>
                  <div
                    ref={(el) => { lineRefs.current[index] = el; }}
                    className={`pointer-events-none absolute bg-gradient-to-${index % 2 === 0 ? 'b' : 'r'} from-primary/30 to-transparent ${pos.line} ${hiddenClass}`}
                  />
                  <div
                    ref={(el) => { nodeRefs.current[index] = el; }}
                    className={`absolute ${pos.node} z-20 flex max-w-[180px] flex-col gap-2 ${pos.align} ${hiddenClass}`}
                  >
                    <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white shadow-lg shadow-slate-200/50 ring-1 ring-slate-100">
                      <feature.icon className="h-6 w-6 text-primary" />
                    </div>
                    <div ref={(el) => { labelRefs.current[index] = el; }} className={hiddenClass}>
                      <div className="font-semibold text-foreground">{feature.label}</div>
                      <div className="text-xs text-muted">{feature.description}</div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>


        </div>
      </div>
    </section>
  );
}
