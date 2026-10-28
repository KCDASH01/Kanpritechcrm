import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import Providers from './providers';
import './globals.css';

const inter = Inter({ subsets: ['latin'] });

export const metadata: Metadata = {
  metadataBase: new URL('https://growneq.com'),
  title: {
    default: 'Growneq CRM — Leads, Sales Pipeline and Team Performance',
    template: '%s',
  },
  description: 'Bring your leads, sales pipeline, customer conversations, and team performance together with Growneq CRM.',
  applicationName: 'Growneq CRM',
  openGraph: {
    title: 'Growneq CRM — Leads, Sales Pipeline and Team Performance',
    description: 'Bring your leads, sales pipeline, customer conversations, and team performance together with Growneq CRM.',
    url: '/',
    siteName: 'Growneq CRM',
    type: 'website',
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
