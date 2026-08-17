'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import {
  Inbox,
  Copy,
  Search,
  Download,
  FileText,
  FileImage,
  FileArchive,
  FileCode,
  Clock,
  User,
} from 'lucide-react';
import { SectionTitle } from '@/components/ui/SectionTitle';
import { useReducedMotion } from '@/hooks/useReducedMotion';

const inboxFiles = [
  { name: 'Q3_Report.pdf', size: '2.4 MB', sender: '张经理', time: '10:23', type: 'pdf' },
  { name: 'homepage_v3.fig', size: '18 MB', sender: '设计组', time: '昨天', type: 'image' },
  { name: 'project_assets.zip', size: '128 MB', sender: '李工', time: '昨天', type: 'zip' },
  { name: 'meeting_notes.docx', size: '856 KB', sender: '王助理', time: '8月4日', type: 'doc' },
];

function FileIcon({ type }: { type: string }) {
  const className = 'h-4 w-4 shrink-0';
  switch (type) {
    case 'pdf':
      return <FileText className={`${className} text-rose-500`} />;
    case 'image':
      return <FileImage className={`${className} text-purple-500`} />;
    case 'zip':
      return <FileArchive className={`${className} text-amber-500`} />;
    case 'doc':
      return <FileCode className={`${className} text-blue-500`} />;
    default:
      return <FileText className={`${className} text-slate-500`} />;
  }
}

export function FileInbox() {
  const sectionRef = useRef<HTMLElement>(null);
  const stageRef = useRef<HTMLDivElement>(null);
  const cardRef = useRef<HTMLDivElement>(null);
  const rowsRef = useRef<HTMLDivElement[]>([]);
  const badgeRef = useRef<HTMLDivElement>(null);
  const prefersReducedMotion = useReducedMotion();

  useEffect(() => {
    if (prefersReducedMotion || !sectionRef.current || !stageRef.current || !cardRef.current) return;

    gsap.registerPlugin(ScrollTrigger);

    const isDesktop = window.innerWidth >= 768;
    if (!isDesktop) return;

    const ctx = gsap.context(() => {
      const tl = gsap.timeline({
        scrollTrigger: {
          trigger: sectionRef.current,
          start: 'top 75%',
          end: '+=80%',
          scrub: 0.8,
        },
      });

      tl.fromTo(
        cardRef.current,
        {
          rotationX: 16,
          rotationY: -24,
          rotationZ: -4,
          scale: 0.86,
          opacity: 0,
          y: 60,
        },
        {
          rotationX: 0,
          rotationY: 0,
          rotationZ: 0,
          scale: 1,
          opacity: 1,
          y: 0,
          duration: 1,
          ease: 'none',
        },
        0
      );

      if (rowsRef.current.length) {
        tl.fromTo(
          rowsRef.current,
          { x: 80, opacity: 0 },
          {
            x: 0,
            opacity: 1,
            stagger: 0.08,
            duration: 0.7,
            ease: 'none',
          },
          0.35
        );
      }

      if (badgeRef.current) {
        tl.fromTo(
          badgeRef.current,
          { scale: 0, opacity: 0 },
          { scale: 1, opacity: 1, duration: 0.25, ease: 'back.out(2)' },
          0.85
        );
      }
    }, sectionRef);

    return () => ctx.revert();
  }, [prefersReducedMotion]);

  const hiddenClass = prefersReducedMotion ? '' : 'opacity-0';

  return (
    <section id="inbox" ref={sectionRef} className="relative overflow-hidden bg-white py-24">
      {/* 顶部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 top-0 z-0 h-48 bg-gradient-to-b from-slate-50 to-transparent" />

      {/* 底部背景过渡 */}
      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 h-48 bg-gradient-to-t from-slate-50 to-transparent" />

      <div className="section-container relative">
        <SectionTitle
          eyebrow="文件信箱"
          title="让文件自己找到你"
          description="生成专属信箱地址，他人无需注册即可向你投递文件。"
          align="center"
        />

        {prefersReducedMotion ? (
          <div className="mt-16 flex justify-center">
            <InboxCard hiddenClass="" badgeRef={badgeRef} rowsRef={rowsRef} />
          </div>
        ) : (
          <>
            {/* Desktop 3D stage */}
            <div
              ref={stageRef}
              className="relative mx-auto mt-16 hidden h-[560px] w-full max-w-6xl md:block"
              style={{ perspective: '1200px' }}
            >
              <div
                ref={cardRef}
                className={`absolute left-1/2 top-1/2 w-[min(92vw,960px)] -translate-x-1/2 -translate-y-1/2 will-change-transform ${hiddenClass}`}
              >
                <InboxCard hiddenClass={hiddenClass} badgeRef={badgeRef} rowsRef={rowsRef} />
              </div>
            </div>
            {/* Mobile static layout */}
            <div className="section-container relative mt-12 md:hidden">
              <InboxCard hiddenClass="" badgeRef={badgeRef} rowsRef={rowsRef} />
            </div>
          </>
        )}
      </div>
    </section>
  );
}

interface InboxCardProps {
  hiddenClass: string;
  badgeRef: React.RefObject<HTMLDivElement>;
  rowsRef: React.RefObject<HTMLDivElement[]>;
}

function InboxCard({ hiddenClass, badgeRef, rowsRef }: InboxCardProps) {
  return (
    <div className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
      <div className="flex h-auto min-h-[420px] flex-col sm:h-[480px] sm:flex-row">
        {/* 左侧：信箱信息 */}
        <div className="w-full border-b border-slate-200 bg-slate-50 p-5 sm:w-64 sm:border-b-0 sm:border-r">
          <div className="mb-6 flex items-center gap-2">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-white">
              <Inbox className="h-5 w-5" />
            </div>
            <span className="font-semibold text-foreground">文件信箱</span>
          </div>

          <div className="mb-5 rounded-xl border border-slate-200 bg-white p-4">
            <div className="mb-2 text-xs text-muted">专属收件地址</div>
            <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1.5">
              <input
                type="text"
                readOnly
                value="yoliark.com/in/a8Xk2"
                className="flex-1 bg-transparent text-xs text-foreground outline-none"
              />
              <button className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary text-white transition-colors hover:bg-primary-dark">
                <Copy className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-1">
            <div className="rounded-xl border border-slate-200 bg-white p-3">
              <div className="text-xl font-bold text-foreground">4</div>
              <div className="text-xs text-muted">收到文件</div>
            </div>
            <div className="rounded-xl border border-slate-200 bg-white p-3">
              <div className="text-xl font-bold text-foreground">149 MB</div>
              <div className="text-xs text-muted">总大小</div>
            </div>
          </div>
        </div>

        {/* 右侧：文件列表 */}
        <div className="flex-1 overflow-hidden p-4 sm:p-6">
          <div className="mb-4 flex items-center justify-between sm:mb-6">
            <div className="flex items-center gap-2">
              <h3 className="text-base font-semibold text-foreground sm:text-lg">收到的文件</h3>
              <div
                ref={badgeRef}
                className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-600"
              >
                3 新
              </div>
            </div>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
              <input
                type="text"
                placeholder="搜索文件..."
                className="w-40 rounded-lg bg-slate-100 py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-primary/20 sm:w-56"
              />
            </div>
          </div>

          <div className="max-h-[320px] overflow-y-auto rounded-xl border border-slate-200 sm:max-h-none">
            <div className="grid grid-cols-12 gap-4 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium text-muted">
              <div className="col-span-5 sm:col-span-4">名称</div>
              <div className="col-span-2 hidden sm:block">大小</div>
              <div className="col-span-3 sm:col-span-2">发送者</div>
              <div className="col-span-3 sm:col-span-2">时间</div>
              <div className="col-span-4 text-right sm:col-span-2 sm:text-left">操作</div>
            </div>
            {inboxFiles.map((file, index) => (
              <div
                key={file.name}
                ref={(el) => {
                  if (el && rowsRef.current) rowsRef.current[index] = el;
                }}
                className="grid grid-cols-12 gap-4 border-b border-slate-100 px-4 py-3 text-sm last:border-0 hover:bg-slate-50"
              >
                <div className="col-span-5 flex items-center gap-2 sm:col-span-4">
                  <FileIcon type={file.type} />
                  <span className="truncate text-foreground">{file.name}</span>
                </div>
                <div className="col-span-2 hidden items-center text-muted sm:flex">{file.size}</div>
                <div className="col-span-3 flex items-center gap-1.5 text-muted sm:col-span-2">
                  <User className="h-3 w-3 shrink-0" />
                  <span className="truncate">{file.sender}</span>
                </div>
                <div className="col-span-3 flex items-center gap-1.5 text-muted sm:col-span-2">
                  <Clock className="h-3 w-3 shrink-0" />
                  <span>{file.time}</span>
                </div>
                <div className="col-span-4 flex justify-end sm:col-span-2 sm:justify-start">
                  <button className="inline-flex h-9 min-w-[44px] items-center justify-center gap-1 rounded-lg bg-slate-100 px-3 text-xs font-medium text-foreground transition-colors hover:bg-slate-200">
                    <Download className="h-4 w-4" />
                    <span className="hidden sm:inline">下载</span>
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
