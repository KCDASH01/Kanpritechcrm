'use client';

import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { ScheduleAlertFab } from './ScheduleAlertFab';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex h-screen overflow-hidden bg-gray-50">
      <Sidebar />
      <div className="flex-1 flex flex-col overflow-hidden relative">
        <Topbar />
        <main className="flex-1 overflow-y-auto p-6">
          {children}
        </main>
      </div>
      <ScheduleAlertFab />
    </div>
  );
}
