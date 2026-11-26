import { ReactNode } from "react";
import { AppSidebar } from "./AppSidebar";
import { useIdleTimeout } from "@/hooks/useIdleTimeout";

export function AppLayout({ children }: { children: ReactNode }) {
  useIdleTimeout();
  return (
    <div className="flex min-h-screen w-full bg-background">
      <AppSidebar />
      <main className="flex-1 min-w-0 lg:pl-0">
        <div className="p-4 sm:p-6 lg:p-8 pt-16 lg:pt-8">
          {children}
        </div>
      </main>
    </div>
  );
}

// noop: harmless touch