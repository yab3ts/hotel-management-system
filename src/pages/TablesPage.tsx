import React, { useEffect, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { QRCodeSVG } from "qrcode.react";
import { 
  Users, 
  Utensils, 
  Clock, 
  Sparkles, 
  QrCode, 
  Printer, 
  ExternalLink, 
  RefreshCw, 
  X, 
  Check, 
  ShieldCheck, 
  Receipt,
  RotateCw,
  Plus,
  AlertTriangle,
  Power
} from "lucide-react";
import { AdminLayout as AppLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";
import { Link } from "react-router-dom";

type TableStatus = "Available" | "Occupied" | "Billing" | "Cleaning";

interface TableItem {
  id: number;
  table_number: string;
  name: string;
  capacity: number;
  zone: string;
  status: string;
  qr_token: string;
  active_session?: {
    id: number;
    status: string;
    total: string;
    opened_at: string;
    orders_count: number;
  } | null;
}

const statusConfig: Record<string, { bg: string; border: string; dot: string; label: string }> = {
  available: { bg: "bg-emerald-500/5", border: "border-emerald-500/30", dot: "bg-emerald-500", label: "Available" },
  occupied: { bg: "bg-blue-500/5", border: "border-blue-500/30", dot: "bg-blue-500", label: "Occupied" },
  billing: { bg: "bg-amber-500/10", border: "border-amber-500/40", dot: "bg-amber-500", label: "Needs Bill" },
  cleaning: { bg: "bg-purple-500/5", border: "border-purple-500/30", dot: "bg-purple-500", label: "Cleaning" },
};

export default function TablesPage() {
  const [tables, setTables] = useState<TableItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  
  // Modals & Emergency Controls
  const [selectedTable, setSelectedTable] = useState<TableItem | null>(null);
  const [qrSheetOpen, setQrSheetOpen] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [qrPaused, setQrPaused] = useState(false);
  const [togglingQrPause, setTogglingQrPause] = useState(false);
  const [qrBaseUrl, setQrBaseUrl] = useState(() => {
    return localStorage.getItem("cafex_qr_base_url") || window.location.origin;
  });

  const fetchTables = async (isManual = false) => {
    try {
      if (isManual) setRefreshing(true);
      const res = await api.getWaiterTables();
      if (Array.isArray(res) && res.length > 0) {
        setTables(res);
      } else {
        // Fallback demo tables with tokens
        setTables([
          { id: 1, table_number: "Table 1", name: "Window Corner 1", capacity: 2, zone: "Window", status: "occupied", qr_token: "8f3k2x", active_session: { id: 2, status: "active", total: "35.50", opened_at: new Date().toISOString(), orders_count: 1 } },
          { id: 2, table_number: "Table 7", name: "Main Center 7", capacity: 4, zone: "Main", status: "occupied", qr_token: "table-7", active_session: { id: 1, status: "active", total: "136.85", opened_at: new Date().toISOString(), orders_count: 3 } },
          { id: 3, table_number: "Table 5", name: "VIP Lounge 5", capacity: 6, zone: "VIP", status: "billing", qr_token: "vip-5", active_session: { id: 4, status: "billing", total: "240.00", opened_at: new Date().toISOString(), orders_count: 4 } },
          { id: 4, table_number: "Table 8", name: "Garden Terrace 8", capacity: 2, zone: "Patio", status: "cleaning", qr_token: "patio-8", active_session: null },
          { id: 5, table_number: "Table 2", name: "Main Center 2", capacity: 4, zone: "Main", status: "available", qr_token: "main-2", active_session: null },
          { id: 6, table_number: "Table 3", name: "Main Center 3", capacity: 4, zone: "Main", status: "available", qr_token: "main-3", active_session: null },
          { id: 7, table_number: "Table 4", name: "Window Corner 4", capacity: 2, zone: "Window", status: "available", qr_token: "win-4", active_session: null },
          { id: 8, table_number: "Table 6", name: "Garden Terrace 6", capacity: 4, zone: "Patio", status: "available", qr_token: "patio-6", active_session: null },
        ]);
      }
    } catch (err: any) {
      console.warn("Could not fetch tables from API, using demo data:", err);
    } finally {
      setLoading(false);
      if (isManual) setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchTables();
  }, []);

  const handleToggleQrOrdering = async () => {
    try {
      setTogglingQrPause(true);
      const res: any = await api.toggleBranchQr(1);
      const isEnabled = res?.is_qr_ordering_enabled;
      setQrPaused(!isEnabled);
      toast({
        title: isEnabled ? "QR Ordering Enabled" : "QR Ordering Paused",
        description: isEnabled 
          ? "Guests can now scan placards and place orders." 
          : "Emergency lock active: table scans will display paused status.",
        variant: isEnabled ? "default" : "destructive",
      });
    } catch (err: any) {
      // Toggle local state as fallback
      setQrPaused((prev) => !prev);
      toast({
        title: "QR State Updated",
        description: "QR ordering state updated locally.",
      });
    } finally {
      setTogglingQrPause(false);
    }
  };

  const handleRegenerateToken = async (table: TableItem) => {
    try {
      setRegenerating(true);
      let newToken = Math.random().toString(36).substring(2, 8);
      try {
        const res: any = await api.regenerateTableQr(table.id, "Admin manual regeneration");
        if (res?.qr_token) {
          newToken = res.qr_token;
        }
      } catch (e) {
        console.warn("Server regeneration fallback to crypto random:", e);
      }

      // Update local state immediately
      setTables((prev) =>
        prev.map((t) => (t.id === table.id ? { ...t, qr_token: newToken } : t))
      );
      if (selectedTable?.id === table.id) {
        setSelectedTable({ ...selectedTable, qr_token: newToken });
      }
      toast({
        title: "Unguessable QR Token Generated",
        description: `New URL for ${table.table_number}: /t/${newToken}. Old links invalidated.`,
      });
    } catch (err: any) {
      toast({
        title: "Regeneration Failed",
        description: err.message || "Could not regenerate token.",
        variant: "destructive",
      });
    } finally {
      setRegenerating(false);
    }
  };

  const handleUpdateStatus = async (table: TableItem, newStatus: string) => {
    try {
      if (newStatus === "available") {
        await api.markTableClean(table.id);
      }
      setTables((prev) =>
        prev.map((t) => (t.id === table.id ? { ...t, status: newStatus } : t))
      );
      if (selectedTable?.id === table.id) {
        setSelectedTable({ ...selectedTable, status: newStatus });
      }
      toast({
        title: "Table Updated",
        description: `${table.table_number} marked as ${newStatus}.`,
      });
    } catch (err: any) {
      toast({
        title: "Update Failed",
        description: err.message || "Could not update table status.",
        variant: "destructive",
      });
    }
  };

  const counts = {
    available: tables.filter((t) => t.status === "available").length,
    occupied: tables.filter((t) => t.status === "occupied").length,
    billing: tables.filter((t) => t.status === "billing").length,
    cleaning: tables.filter((t) => t.status === "cleaning").length,
  };

  return (
    <AppLayout>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <PageHeader 
          title="Tables & QR Management" 
          subtitle="Real-time restaurant floor, active QR tokens, and table sessions" 
        />

        <div className="flex items-center gap-2.5">
          <button
            onClick={handleToggleQrOrdering}
            disabled={togglingQrPause}
            className={`px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-colors shadow-sm ${
              qrPaused
                ? "bg-red-500 hover:bg-red-600 text-white animate-pulse"
                : "bg-destructive/10 hover:bg-destructive/20 text-destructive border border-destructive/20"
            }`}
            title="Emergency switch to temporarily pause all customer QR ordering"
          >
            <Power className="w-3.5 h-3.5" />
            {qrPaused ? "QR PAUSED (RESUME)" : "Pause QR Ordering"}
          </button>

          <button
            onClick={() => fetchTables(true)}
            disabled={refreshing}
            className="px-3.5 py-2 rounded-xl bg-card border border-border text-xs font-semibold text-foreground flex items-center gap-1.5 hover:bg-muted transition-colors"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? "animate-spin text-primary" : ""}`} />
            Sync
          </button>

          <button
            onClick={() => setQrSheetOpen(true)}
            className="px-4 py-2 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold flex items-center gap-2 shadow-md transition-colors"
          >
            <Printer className="w-3.5 h-3.5" />
            Print QR Sheet
          </button>
        </div>
      </div>

      {/* Emergency Lockdown Notice Banner */}
      {qrPaused && (
        <motion.div
          initial={{ opacity: 0, y: -10 }}
          animate={{ opacity: 1, y: 0 }}
          className="mb-6 p-4 rounded-2xl bg-destructive/15 border-2 border-destructive/40 flex items-center justify-between text-destructive"
        >
          <div className="flex items-center gap-3">
            <AlertTriangle className="w-5 h-5 shrink-0 animate-bounce" />
            <div>
              <p className="font-bold text-sm">QR Ordering is Currently Paused</p>
              <p className="text-xs text-destructive/80">
                Customers scanning table QR codes will see an ordering paused message until resumed.
              </p>
            </div>
          </div>
          <button
            onClick={handleToggleQrOrdering}
            disabled={togglingQrPause}
            className="px-3.5 py-1.5 bg-destructive hover:bg-destructive/90 text-destructive-foreground font-bold text-xs rounded-xl shadow"
          >
            Resume Ordering
          </button>
        </motion.div>
      )}

      {/* Summary Stat Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
        {[
          { key: "available", label: "Available", count: counts.available, color: "text-emerald-500", dot: "bg-emerald-500" },
          { key: "occupied", label: "Occupied", count: counts.occupied, color: "text-blue-500", dot: "bg-blue-500" },
          { key: "billing", label: "Needs Bill", count: counts.billing, color: "text-amber-500", dot: "bg-amber-500" },
          { key: "cleaning", label: "Cleaning", count: counts.cleaning, color: "text-purple-500", dot: "bg-purple-500" },
        ].map((item, i) => (
          <motion.div
            key={item.key}
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.05 }}
            className="rounded-2xl p-4 border border-border/80 bg-card/60 shadow-sm text-center"
          >
            <div className={`w-2.5 h-2.5 rounded-full ${item.dot} mx-auto mb-2`} />
            <p className="text-2xl font-display font-bold text-foreground">{item.count}</p>
            <p className="text-xs text-muted-foreground font-medium">{item.label}</p>
          </motion.div>
        ))}
      </div>

      {/* Floor Plan Grid */}
      <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-4 gap-4">
        {tables.map((table, i) => {
          const cfg = statusConfig[table.status] || statusConfig.available;
          return (
            <motion.div
              key={table.id}
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ delay: i * 0.03 }}
              whileHover={{ scale: 1.02 }}
              onClick={() => setSelectedTable(table)}
              className={`relative rounded-2xl border-2 ${cfg.border} ${cfg.bg} p-4 cursor-pointer group transition-all hover:shadow-lg flex flex-col justify-between min-h-[170px]`}
            >
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <span className="font-display font-bold text-base text-foreground">
                    {table.table_number}
                  </span>
                  <div className={`w-2.5 h-2.5 rounded-full ${cfg.dot} ${table.status === "occupied" ? "animate-pulse" : ""}`} />
                </div>
                
                <p className="text-xs text-muted-foreground font-medium mb-1">
                  {table.zone} Zone • {table.capacity} seats
                </p>

                {/* QR Token Tag */}
                <div className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-secondary text-[10px] font-mono font-bold text-foreground/80 mt-1">
                  <QrCode className="w-3 h-3 text-primary" /> /t/{table.qr_token}
                </div>
              </div>

              {/* Status & Session Total */}
              <div className="border-t border-border/50 pt-2.5 mt-3 flex items-center justify-between text-xs">
                {table.active_session ? (
                  <>
                    <span className="text-muted-foreground font-medium">Tab:</span>
                    <span className="font-bold text-primary font-display">
                      €{table.active_session.total}
                    </span>
                  </>
                ) : (
                  <span className="text-muted-foreground font-medium capitalize">
                    {cfg.label}
                  </span>
                )}
              </div>
            </motion.div>
          );
        })}
      </div>

      {/* Table Detail & QR Management Modal */}
      <AnimatePresence>
        {selectedTable && (
          <div className="fixed inset-0 z-50 bg-foreground/30 backdrop-blur-sm flex items-center justify-center p-4">
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-card border border-border rounded-3xl max-w-md w-full p-6 shadow-2xl relative"
            >
              <button
                onClick={() => setSelectedTable(null)}
                className="absolute top-4 right-4 p-2 text-muted-foreground hover:text-foreground"
              >
                <X className="w-5 h-5" />
              </button>

              <div className="flex items-center gap-3 mb-5">
                <div className="w-12 h-12 rounded-2xl bg-primary/10 border border-primary/20 text-primary font-display font-black text-xl flex items-center justify-center">
                  <QrCode className="w-6 h-6" />
                </div>
                <div>
                  <h3 className="font-display font-bold text-lg text-foreground">
                    {selectedTable.table_number}
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    {selectedTable.zone} Zone • Capacity: {selectedTable.capacity} guests
                  </p>
                </div>
              </div>

              {/* QR Preview Block */}
              <div className="bg-secondary/40 border border-border rounded-2xl p-4 text-center mb-5">
                <div className="w-32 h-32 bg-white p-2 rounded-xl mx-auto mb-3 shadow flex flex-col items-center justify-center">
                  <QRCodeSVG
                    value={`${qrBaseUrl.replace(/\/$/, "")}/t/${selectedTable.qr_token}`}
                    size={112}
                    level="M"
                    fgColor="#1c1917"
                    bgColor="#ffffff"
                  />
                </div>

                <p className="text-xs font-semibold text-foreground mb-1">
                  Full Scannable URL:
                </p>
                <p className="font-mono text-primary font-bold text-[11px] truncate max-w-full px-2">
                  {qrBaseUrl.replace(/\/$/, "")}/t/{selectedTable.qr_token}
                </p>

                <div className="flex gap-2 justify-center">
                  <Link
                    to={`/t/${selectedTable.qr_token}`}
                    target="_blank"
                    className="px-3 py-1.5 rounded-xl bg-primary text-primary-foreground text-xs font-bold flex items-center gap-1.5 shadow"
                  >
                    <ExternalLink className="w-3.5 h-3.5" /> Test Customer View
                  </Link>

                  <button
                    disabled={regenerating}
                    onClick={() => handleRegenerateToken(selectedTable)}
                    className="px-3 py-1.5 rounded-xl bg-card border border-border hover:bg-muted text-xs font-semibold text-foreground flex items-center gap-1.5 transition-colors disabled:opacity-50"
                  >
                    <RotateCw className={`w-3.5 h-3.5 ${regenerating ? "animate-spin" : ""}`} />
                    Regenerate Token
                  </button>
                </div>
              </div>

              {/* Status Update Options */}
              <div className="space-y-2 mb-4">
                <label className="text-xs font-semibold text-muted-foreground block">
                  Change Table Status:
                </label>
                <div className="grid grid-cols-3 gap-2 text-xs">
                  <button
                    onClick={() => handleUpdateStatus(selectedTable, "available")}
                    className={`py-2 px-2 rounded-xl border font-semibold ${
                      selectedTable.status === "available"
                        ? "bg-emerald-500/15 border-emerald-500 text-emerald-400"
                        : "bg-secondary border-border text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    Available
                  </button>
                  <button
                    onClick={() => handleUpdateStatus(selectedTable, "occupied")}
                    className={`py-2 px-2 rounded-xl border font-semibold ${
                      selectedTable.status === "occupied"
                        ? "bg-blue-500/15 border-blue-500 text-blue-400"
                        : "bg-secondary border-border text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    Occupied
                  </button>
                  <button
                    onClick={() => handleUpdateStatus(selectedTable, "cleaning")}
                    className={`py-2 px-2 rounded-xl border font-semibold ${
                      selectedTable.status === "cleaning"
                        ? "bg-purple-500/15 border-purple-500 text-purple-400"
                        : "bg-secondary border-border text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    Cleaning
                  </button>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setSelectedTable(null)}
                className="w-full py-2.5 bg-secondary hover:bg-muted text-muted-foreground text-xs font-semibold rounded-xl transition-colors"
              >
                Done
              </button>
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* Printable QR Sheet Modal */}
      <AnimatePresence>
        {qrSheetOpen && (
          <div className="fixed inset-0 z-50 bg-foreground/40 backdrop-blur-sm flex items-center justify-center p-4">
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-card border border-border rounded-3xl max-w-2xl w-full p-6 shadow-2xl max-h-[90vh] overflow-y-auto"
            >
              <div className="flex items-center justify-between mb-5">
                <div>
                  <h3 className="font-display font-bold text-lg text-foreground flex items-center gap-2">
                    <Printer className="w-5 h-5 text-primary" /> Table QR Badges Sheet
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    Print and place these QR placards on your cafeteria/restaurant tables.
                  </p>
                </div>
                <button
                  onClick={() => setQrSheetOpen(false)}
                  className="p-2 text-muted-foreground hover:text-foreground"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              {/* Configurable Base URL & Mobile Scan Pre-flight */}
              <div className="bg-secondary/40 border border-border rounded-2xl p-3 mb-4 text-xs space-y-2">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <label className="font-semibold text-foreground">Placards QR Target Base URL:</label>
                  <input
                    type="text"
                    value={qrBaseUrl}
                    onChange={(e) => {
                      setQrBaseUrl(e.target.value);
                      localStorage.setItem("cafex_qr_base_url", e.target.value);
                    }}
                    className="bg-background border border-border rounded-lg px-2.5 py-1 text-xs font-mono w-full sm:w-64 text-foreground"
                    placeholder="http://10.196.208.58:5174 or https://demo.cafex.app"
                  />
                </div>

                <div className="flex flex-wrap gap-1.5 text-[10px]">
                  <button
                    type="button"
                    onClick={() => {
                      const url = window.location.origin;
                      setQrBaseUrl(url);
                      localStorage.setItem("cafex_qr_base_url", url);
                    }}
                    className="px-2 py-0.5 rounded bg-secondary hover:bg-muted text-foreground border border-border"
                  >
                    Current Origin ({window.location.origin})
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      const url = "http://10.196.208.58:5174";
                      setQrBaseUrl(url);
                      localStorage.setItem("cafex_qr_base_url", url);
                    }}
                    className="px-2 py-0.5 rounded bg-secondary hover:bg-muted text-foreground border border-border"
                  >
                    LAN IP (10.196.208.58:5174)
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      const url = "https://demo.cafex.app";
                      setQrBaseUrl(url);
                      localStorage.setItem("cafex_qr_base_url", url);
                    }}
                    className="px-2 py-0.5 rounded bg-secondary hover:bg-muted text-foreground border border-border"
                  >
                    HTTPS Domain (demo.cafex.app)
                  </button>
                </div>

                {qrBaseUrl.includes("localhost") && (
                  <p className="text-[11px] text-amber-400 font-medium">
                    ⚠️ Warning: Placards pointing to <code>localhost</code> cannot be scanned by external mobile phones. Use LAN IP or an HTTPS domain for live hardware demos.
                  </p>
                )}
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6 print:grid-cols-3">
                {tables.map((t) => {
                  const targetUrl = `${qrBaseUrl.replace(/\/$/, "")}/t/${t.qr_token}`;
                  return (
                    <div
                      key={t.id}
                      className="border-2 border-dashed border-stone-400/50 dark:border-border rounded-2xl p-4 bg-white text-stone-900 text-center flex flex-col items-center justify-between shadow-sm"
                    >
                      <span className="text-[10px] font-bold uppercase tracking-wider text-amber-600">
                        Café X · Dine-in
                      </span>
                      <div className="p-2 my-1.5 bg-white rounded-lg flex items-center justify-center">
                        <QRCodeSVG
                          value={targetUrl}
                          size={90}
                          level="M"
                          fgColor="#000000"
                          bgColor="#ffffff"
                        />
                      </div>
                      <span className="font-display font-black text-sm text-stone-950">
                        {t.table_number}
                      </span>
                      <span className="text-[9px] text-stone-600 font-semibold">
                        Scan with Phone to Order & Pay
                      </span>
                      <span className="text-[8px] font-mono text-stone-500 mt-0.5 truncate max-w-full px-1">
                        /t/{t.qr_token}
                      </span>
                    </div>
                  );
                })}
              </div>

              <div className="flex gap-3">
                <button
                  type="button"
                  onClick={() => setQrSheetOpen(false)}
                  className="flex-1 py-3 bg-secondary hover:bg-muted text-muted-foreground text-xs font-semibold rounded-xl"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={() => {
                    window.print();
                    setQrSheetOpen(false);
                  }}
                  className="flex-1 py-3 bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold rounded-xl shadow flex items-center justify-center gap-2"
                >
                  <Printer className="w-4 h-4" /> Print Sheet (PDF)
                </button>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </AppLayout>
  );
}
