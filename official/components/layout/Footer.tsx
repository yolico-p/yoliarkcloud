'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { Cloud, Heart, Mail } from 'lucide-react';
import { useReducedMotion } from '@/hooks/useReducedMotion';

export function Footer() {
  const footerRef = useRef<HTMLElement>(null);
  const lineRef = useRef<HTMLDivElement>(null);
  const contentRef = useRef<HTMLDivElement>(null);
  const prefersReducedMotion = useReducedMotion();

  useEffect(() => {
    if (prefersReducedMotion) return;
    if (!footerRef.current) return;

    gsap.registerPlugin(ScrollTrigger);

    const ctx = gsap.context(() => {
      gsap.fromTo(
        lineRef.current,
        { scaleX: 0 },
        {
          scaleX: 1,
          duration: 1,
          ease: 'power2.out',
          scrollTrigger: {
            trigger: footerRef.current,
            start: 'top 85%',
            toggleActions: 'play none none reverse',
          },
        }
      );

      gsap.fromTo(
        contentRef.current,
        { y: 24, opacity: 0 },
        {
          y: 0,
          opacity: 1,
          duration: 0.8,
          ease: 'power2.out',
          scrollTrigger: {
            trigger: footerRef.current,
            start: 'top 85%',
            toggleActions: 'play none none reverse',
          },
        }
      );
    }, footerRef);

    return () => ctx.revert();
  }, [prefersReducedMotion]);

  return (
    <footer ref={footerRef} className="border-t border-slate-200 bg-white py-12">
      <div className="section-container">
        <div
          ref={contentRef}
          className="flex flex-col items-center justify-between gap-6 md:flex-row"
          style={{ opacity: prefersReducedMotion ? 1 : undefined }}
        >
          <div className="flex items-center gap-2 text-lg font-semibold text-foreground">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-white">
              <Cloud className="h-4 w-4" />
            </div>
            <span>柚舟Cloud</span>
          </div>

          <div className="flex flex-wrap items-center justify-center gap-6 text-sm text-muted">
            <a href="https://yolico.net" target="_blank" rel="noopener noreferrer" className="inline-flex min-h-[44px] items-center hover:text-foreground">
              作者主页
            </a>
            <a href="https://yoliark.com" target="_blank" rel="noopener noreferrer" className="inline-flex min-h-[44px] items-center hover:text-foreground">
              产品官网
            </a>
            <a href="mailto:mail@hiyy.top" className="inline-flex min-h-[44px] items-center gap-1 hover:text-foreground">
              <Mail className="h-4 w-4" />
              联系作者
            </a>
          </div>
        </div>

        <div
          ref={lineRef}
          className="my-8 h-px origin-center bg-slate-100"
          style={{ transform: prefersReducedMotion ? 'scaleX(1)' : undefined }}
        />

        <div className="flex flex-col items-center justify-between gap-2 text-sm text-muted md:flex-row">
          <p>Copyright © 2025-2026 yolico（柚柠可）. All rights reserved.</p>
          <p className="inline-flex items-center gap-1">
            用 <Heart className="h-3.5 w-3.5 text-red-500" /> 构建 · AGPL-3.0
          </p>
        </div>
      </div>
    </footer>
  );
}
