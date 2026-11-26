import { ReactNode } from "react";
import { AdminSidebar } from "./AdminSidebar";
import { useIdleTimeout } from "@/hooks/useIdleTimeout";

export function AdminLayout({ children }: { children: ReactNode }) {
  useIdleTimeout();
  return (
    <div className="flex min-h-screen w-full bg-background">
      <AdminSidebar />
      <main className="flex-1 min-w-0 w-full">
        <div className="h-14 lg:hidden" />
        <div className="px-3 py-3 sm:p-6 lg:p-8">
          {children}
        </div>
      </main>
    </div>
  );
}

// noop: harmless touch