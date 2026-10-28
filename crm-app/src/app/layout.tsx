import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import Providers from './providers';
import './globals.css';

const inter = Inter({ subsets: ['latin'] });

export const metadata: Metadata = {
  metadataBase: new URL('https://growneq.com'),
  title: {
    default: 'Growneq CRM | Lead Management & Sales Growth Platform',
    template: '%s | Growneq CRM',
  },
  description: 'Growneq CRM helps businesses manage leads, organize sales pipelines, track team performance, and streamline customer relationships in one platform.',
  applicationName: 'Growneq CRM',
  icons: {
    icon: [
      { url: '/branding/growneq-icon-32.png', type: 'image/png', sizes: '32x32' },
      { url: '/branding/growneq-meta-app-icon-1024.png', type: 'image/png', sizes: '1024x1024' },
    ],
    apple: [{ url: '/branding/growneq-icon-180.png', type: 'image/png', sizes: '180x180' }],
  },
  openGraph: {
    title: 'Growneq CRM | Lead Management & Sales Growth Platform',
    description: 'Growneq CRM helps businesses manage leads, organize sales pipelines, track team performance, and streamline customer relationships in one platform.',
    url: '/',
    siteName: 'Growneq CRM',
    type: 'website',
    images: [{ url: '/branding/growneq-social-preview.png', width: 1200, height: 630, alt: 'Growneq CRM' }],
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Growneq CRM | Lead Management & Sales Growth Platform',
    description: 'Growneq CRM helps businesses manage leads, organize sales pipelines, track team performance, and streamline customer relationships in one platform.',
    images: ['/branding/growneq-social-preview.png'],
  },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="h-full">
      <body className={`${inter.className} h-full antialiased`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
