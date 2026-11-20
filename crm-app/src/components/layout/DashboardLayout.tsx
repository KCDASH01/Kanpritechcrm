'use client';

import { useState } from 'react';
import { usePathname } from 'next/navigation';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { ScheduleAlertFab } from './ScheduleAlertFab';
import { FollowUpBuddy } from '@/components/assistant/FollowUpBuddy';
import { DashboardAssistantAudio } from '@/components/assistant/DashboardAssistantAudio';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const isDashboard = usePathname() === '/dashboard';

  return (
    <DashboardAssistantAudio active={isDashboard}>
      <div className="flex h-screen min-h-[100dvh] overflow-hidden bg-gray-50">
        <Sidebar open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />
        <div className="relative flex min-w-0 flex-1 flex-col overflow-hidden">
          <Topbar onOpenSidebar={() => setMobileNavOpen(true)} />
          <main className="min-w-0 flex-1 overflow-x-hidden overflow-y-auto p-3 sm:p-4 lg:p-6">
            {children}
            {isDashboard && <div aria-hidden="true" className="h-[calc(5rem+env(safe-area-inset-bottom))]" />}
          </main>
        </div>
        {!isDashboard && <ScheduleAlertFab />}
        {isDashboard && <FollowUpBuddy />}
      </div>
    </DashboardAssistantAudio>
  );
}
