import type { Metadata } from 'next'
import { Inter } from 'next/font/google'
import './globals.css'

const inter = Inter({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-inter',
})

export const metadata: Metadata = {
  title: '柚舟Cloud - 轻量、安全、智能化的个人云存储',
  description: '柚舟Cloud 是一款单用户个人网盘，零配置部署，支持文件管理、秒级分享、AI 云助手、文件信箱等功能。',
  keywords: ['柚舟Cloud', 'YoliArkCloud', '个人网盘', '云存储', '文件管理'],
  authors: [{ name: 'yolico', url: 'https://yolico.net' }],
  icons: {
    icon: '/favicon.svg',
  },
  openGraph: {
    title: '柚舟Cloud - 轻量、安全、智能化的个人云存储',
    description: '零配置部署的单用户个人网盘，简单的东西不是最好的，但最好的东西一定是简单的。',
    type: 'website',
    url: 'https://yoliark.com',
  },
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode
}>) {
  return (
    <html lang="zh-CN" className={inter.variable}>
      <body className="min-h-screen font-sans antialiased">{children}</body>
    </html>
  )
}
