'use client';

import { useState } from 'react';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { ScheduleAlertFab } from './ScheduleAlertFab';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  return (
    <div className="flex h-screen min-h-[100dvh] overflow-hidden bg-gray-50">
      <Sidebar open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />
      <div className="relative flex min-w-0 flex-1 flex-col overflow-hidden">
        <Topbar onOpenSidebar={() => setMobileNavOpen(true)} />
        <main className="flex-1 overflow-x-hidden overflow-y-auto p-3 sm:p-4 lg:p-6">
          {children}
        </main>
      </div>
      <ScheduleAlertFab />
    </div>
  );
}
