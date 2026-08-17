'use client';

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { useReducedMotion } from '@/hooks/useReducedMotion';

interface ParallaxLayerProps {
  children: React.ReactNode;
  speed?: number;
  className?: string;
  as?: 'div' | 'span';
}

export function ParallaxLayer({
  children,
  speed = 0.5,
  className,
  as: Component = 'div',
}: ParallaxLayerProps) {
  const divRef = useRef<HTMLDivElement>(null);
  const spanRef = useRef<HTMLSpanElement>(null);
  const prefersReducedMotion = useReducedMotion();

  useEffect(() => {
    if (prefersReducedMotion) return;

    const element = Component === 'span' ? spanRef.current : divRef.current;
    if (!element) return;

    gsap.registerPlugin(ScrollTrigger);

    const tween = gsap.fromTo(
      element,
      { y: -speed * 100 },
      {
        y: speed * 100,
        ease: 'none',
        scrollTrigger: {
          trigger: element,
          start: 'top bottom',
          end: 'bottom top',
          scrub: true,
        },
      }
    );

    return () => {
      tween.scrollTrigger?.kill();
      tween.kill();
    };
  }, [speed, Component, prefersReducedMotion]);

  const combinedClass = `parallax-layer${className ? ` ${className}` : ''}`;

  if (Component === 'span') {
    return (
      <span ref={spanRef} className={combinedClass}>
        {children}
      </span>
    );
  }

  return (
    <div ref={divRef} className={combinedClass}>
      {children}
    </div>
  );
}
