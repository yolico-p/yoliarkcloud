import { Footer } from '@/components/layout/Footer'
import { ScrollContainer } from '@/components/ui/ScrollContainer'
import { Hero } from '@/components/sections/Hero'
import { Features } from '@/components/sections/Features'
import { AIAssistant } from '@/components/sections/AIAssistant'
import { Security } from '@/components/sections/Security'
import { FileInbox } from '@/components/sections/FileInbox'
import { Screenshots } from '@/components/sections/Screenshots'
import { OpenSource } from '@/components/sections/OpenSource'
import { CTA } from '@/components/sections/CTA'

export default function Home() {
  return (
    <ScrollContainer>
      <main>
        <Hero />
        <Features />
        <AIAssistant />
        <Security />
        <FileInbox />
        <Screenshots />
        <OpenSource />
        <CTA />
      </main>
      <Footer />
    </ScrollContainer>
  )
}
