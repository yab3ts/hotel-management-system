import React, { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { WifiOff, RefreshCw } from "lucide-react";

export function NetworkStatusBanner() {
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
    };
  }, []);

  const handleRetry = async () => {
    setRetrying(true);
    try {
      const res = await fetch("/api/health", { cache: "no-store" });
      if (res.ok) {
        setIsOnline(true);
      }
    } catch {
      setIsOnline(false);
    } finally {
      setTimeout(() => setRetrying(false), 600);
    }
  };

  if (isOnline) return null;

  return (
    <AnimatePresence>
      <motion.div
        initial={{ y: -40, opacity: 0 }}
        animate={{ y: 0, opacity: 1 }}
        exit={{ y: -40, opacity: 0 }}
        className="fixed top-0 inset-x-0 z-50 bg-destructive text-destructive-foreground px-4 py-2 text-xs font-semibold flex items-center justify-between shadow-lg"
      >
        <div className="flex items-center gap-2">
          <WifiOff className="w-4 h-4 animate-pulse" />
          <span>Connection lost. Orders and kitchen updates paused until reconnected.</span>
        </div>
        <button
          onClick={handleRetry}
          disabled={retrying}
          className="px-2.5 py-1 rounded bg-black/20 hover:bg-black/30 text-[11px] font-bold flex items-center gap-1 transition-colors"
        >
          <RefreshCw className={`w-3 h-3 ${retrying ? "animate-spin" : ""}`} />
          {retrying ? "Checking..." : "Retry"}
        </button>
      </motion.div>
    </AnimatePresence>
  );
}
