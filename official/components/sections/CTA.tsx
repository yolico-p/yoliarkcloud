'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { ArrowRight, Download } from 'lucide-react';
import { useReducedMotion } from '@/hooks/useReducedMotion';

export function CTA() {
  const sectionRef = useRef<HTMLElement>(null);
  const headlineRef = useRef<HTMLHeadingElement>(null);
  const descRef = useRef<HTMLParagraphElement>(null);
  const buttonsRef = useRef<HTMLDivElement>(null);
  const metaRef = useRef<HTMLParagraphElement>(null);
  const glowRef = useRef<HTMLDivElement>(null);
  const prefersReducedMotion = useReducedMotion();

  useEffect(() => {
    if (prefersReducedMotion) return;
    if (!sectionRef.current) return;

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

      tl.fromTo(
        glowRef.current,
        { opacity: 0, scale: 1.1 },
        { opacity: 1, scale: 1, duration: 1.4, ease: 'power2.out' },
        0
      )
        .fromTo(
          headlineRef.current,
          { scale: 0.92, opacity: 0, filter: 'blur(8px)' },
          { scale: 1, opacity: 1, filter: 'blur(0px)', duration: 1.1, ease: 'power3.out' },
          0.1
        )
        .fromTo(descRef.current, { y: 28, opacity: 0 }, { y: 0, opacity: 1, duration: 0.8, ease: 'power2.out' }, '-=0.6')
        .fromTo(
          buttonsRef.current?.children ? Array.from(buttonsRef.current.children) : [],
          { y: 24, opacity: 0 },
          { y: 0, opacity: 1, duration: 0.65, stagger: 0.1, ease: 'power2.out' },
          '-=0.45'
        )
        .fromTo(metaRef.current, { y: 18, opacity: 0 }, { y: 0, opacity: 1, duration: 0.55, ease: 'power2.out' }, '-=0.35');

      gsap.fromTo(
        headlineRef.current,
        { y: -12 },
        {
          y: 12,
          ease: 'none',
          scrollTrigger: {
            trigger: sectionRef.current,
            start: 'top bottom',
            end: 'bottom top',
            scrub: 1.2,
          },
        }
      );

      gsap.fromTo(
        glowRef.current,
        { y: '-8%', scale: 1.02 },
        {
          y: '8%',
          scale: 1.08,
          ease: 'none',
          scrollTrigger: {
            trigger: sectionRef.current,
            start: 'top bottom',
            end: 'bottom top',
            scrub: 1.5,
          },
        }
      );
    }, sectionRef);

    return () => ctx.revert();
  }, [prefersReducedMotion]);

  return (
    <section
      id="download"
      ref={sectionRef}
      className="relative flex min-h-[90vh] items-center justify-center overflow-hidden bg-cta-gradient py-24"
    >
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-[#eff6ff] to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-white to-transparent" />

      <div
        ref={glowRef}
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_30%,rgba(37,99,235,0.08),transparent_50%)] will-change-transform"
      />

      <div className="section-container relative">
        <div className="mx-auto max-w-4xl text-center">
          <h2
            ref={headlineRef}
            className="text-balance text-4xl font-bold tracking-tight text-foreground will-change-[transform,opacity,filter] sm:text-5xl md:text-6xl"
            style={{ opacity: prefersReducedMotion ? 1 : undefined }}
          >
            准备好部署你的个人网盘了吗？
          </h2>

          <p
            ref={descRef}
            className="mx-auto mt-6 max-w-2xl text-balance text-lg text-muted sm:text-xl"
            style={{ opacity: prefersReducedMotion ? 1 : undefined }}
          >
            零配置、单用户、纯浏览器操作。把数据放回自己的服务器，从现在开始。
          </p>

          <div
            ref={buttonsRef}
            className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row"
          >
            <a
              href="https://github.com/yolico/yoliarkcloud/releases"
              target="_blank"
              rel="noopener noreferrer"
              className="btn-primary group w-full sm:w-auto"
            >
              <Download className="mr-2 h-4 w-4" />
              下载最新版
              <ArrowRight className="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1" />
            </a>
            <a
              href="https://github.com/yolico/yoliarkcloud"
              target="_blank"
              rel="noopener noreferrer"
              className="btn-secondary w-full sm:w-auto"
            >
              查看 GitHub
            </a>
          </div>

          <p
            ref={metaRef}
            className="mt-6 text-xs text-muted"
            style={{ opacity: prefersReducedMotion ? 1 : undefined }}
          >
            支持 PHP 8.0+、SQLite、Nginx/Apache
          </p>
        </div>
      </div>
    </section>
  );
}
