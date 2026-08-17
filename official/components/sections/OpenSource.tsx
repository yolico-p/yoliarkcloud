'use client';

import { Github, ExternalLink } from 'lucide-react';
import { useReducedMotion } from '@/hooks/useReducedMotion';

export function OpenSource() {
  const prefersReducedMotion = useReducedMotion();

  return (
    <section id="opensource" className="relative overflow-hidden bg-surface py-32 sm:py-40">
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-white to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-[#eff6ff] to-transparent" />

      <div className="section-container relative">
        <div className="mx-auto flex max-w-5xl flex-col items-center text-center">
          <span className="mb-6 inline-block text-sm font-medium tracking-widest text-muted uppercase">
            开源透明
          </span>

          <h2 className="max-w-4xl text-balance text-4xl font-bold tracking-tight text-foreground sm:text-5xl md:text-6xl">
            自由使用，开放协作
          </h2>

          <p className="mt-8 max-w-2xl text-balance text-lg leading-relaxed text-muted sm:text-xl">
            柚舟Cloud 以 AGPL-3.0 协议发布。你可以自由使用、修改和分发，
            <span className="text-foreground">网络服务形式的修改也必须公开源代码</span>。
          </p>

          <div className="mt-14 flex flex-col items-center gap-8 sm:flex-row sm:gap-14">
            <a
              href="https://github.com/yolico/yoliarkcloud"
              target="_blank"
              rel="noopener noreferrer"
              className={`group inline-flex min-h-[44px] items-center gap-3 text-3xl font-semibold text-foreground sm:text-4xl ${
                prefersReducedMotion ? '' : 'transition-colors hover:text-primary'
              }`}
            >
              <Github className={`h-9 w-9 ${prefersReducedMotion ? '' : 'transition-transform duration-300 group-hover:-rotate-12'}`} />
              GitHub
              <ExternalLink className={`h-5 w-5 text-muted ${prefersReducedMotion ? '' : 'opacity-0 transition-all duration-300 group-hover:opacity-100'}`} />
            </a>

            <a
              href="https://www.gnu.org/licenses/agpl-3.0.html"
              target="_blank"
              rel="noopener noreferrer"
              className={`group inline-flex min-h-[44px] items-center gap-3 text-3xl font-semibold text-foreground sm:text-4xl ${
                prefersReducedMotion ? '' : 'transition-colors hover:text-primary'
              }`}
            >
              <span className="font-mono">AGPL-3.0</span>
              <ExternalLink className={`h-5 w-5 text-muted ${prefersReducedMotion ? '' : 'opacity-0 transition-all duration-300 group-hover:opacity-100'}`} />
            </a>
          </div>
        </div>
      </div>
    </section>
  );
}
