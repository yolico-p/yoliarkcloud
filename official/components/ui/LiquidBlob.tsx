'use client';

import { useReducedMotion } from '@/hooks/useReducedMotion';

type BlobColor = 'blue' | 'purple' | 'cyan';

interface LiquidBlobProps {
  size?: number;
  color?: BlobColor;
  speed?: number;
  className?: string;
}

const colorPalette: Record<BlobColor, { color: string; glow: string; highlight: string }> = {
  blue: {
    color: 'rgba(37, 99, 235, 0.55)',
    glow: 'rgba(37, 99, 235, 0.45)',
    highlight: 'rgba(147, 197, 253, 0.75)',
  },
  purple: {
    color: 'rgba(124, 58, 237, 0.55)',
    glow: 'rgba(124, 58, 237, 0.45)',
    highlight: 'rgba(196, 181, 253, 0.75)',
  },
  cyan: {
    color: 'rgba(8, 145, 178, 0.55)',
    glow: 'rgba(8, 145, 178, 0.45)',
    highlight: 'rgba(103, 232, 249, 0.75)',
  },
};

function cls(...classes: Array<string | false | undefined>) {
  return classes.filter(Boolean).join(' ');
}

export function LiquidBlob({
  size = 240,
  color = 'blue',
  speed = 8,
  className,
}: LiquidBlobProps) {
  const reducedMotion = useReducedMotion();
  const palette = colorPalette[color];

  return (
    <div
      className={cls('pointer-events-none inline-block', className)}
      style={{ width: size, height: size }}
    >
      <div
        className="liquid-blob h-full w-full"
        style={{
          '--blob-color': palette.color,
          '--blob-glow': palette.glow,
          '--blob-highlight': palette.highlight,
          '--blob-speed': `${speed}s`,
          animationPlayState: reducedMotion ? 'paused' : 'running',
        } as React.CSSProperties}
      />
    </div>
  );
}
