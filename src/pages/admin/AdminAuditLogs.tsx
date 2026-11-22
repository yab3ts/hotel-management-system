import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  ShieldCheck,
  RefreshCw,
  Search,
  Filter,
  AlertCircle,
  KeyRound,
  RotateCcw,
  CheckCircle2,
  XCircle,
  Clock,
  Layers,
  Terminal,
} from "lucide-react";
import { AdminLayout as AppLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

interface AuditLogEntry {
  id: number;
  user_id?: number | null;
  action: string;
  auditable_type?: string | null;
  auditable_id?: number | null;
  payload: Record<string, any>;
  ip_address?: string | null;
  created_at: string;
}

const actionBadges: Record<string, { label: string; color: string; icon: typeof ShieldCheck }> = {
  qr_token_regenerated: {
    label: "QR Token Rotation",
    color: "bg-blue-500/10 text-blue-600 border-blue-200",
    icon: KeyRound,
  },
  qr_ordering_toggled: {
    label: "QR Ordering Toggled",
    color: "bg-amber-500/10 text-amber-600 border-amber-200",
    icon: Layers,
  },
  table_session_settled: {
    label: "Session Settled",
    color: "bg-emerald-500/10 text-emerald-600 border-emerald-200",
    icon: CheckCircle2,
  },
  order_cancelled: {
    label: "Order Cancelled",
    color: "bg-rose-500/10 text-rose-600 border-rose-200",
    icon: XCircle,
  },
  payment_refunded: {
    label: "Payment Refunded",
    color: "bg-purple-500/10 text-purple-600 border-purple-200",
    icon: RotateCcw,
  },
  manager_override: {
    label: "Manager Override",
    color: "bg-destructive/10 text-destructive border-destructive/20",
    icon: ShieldCheck,
  },
};

export default function AdminAuditLogs() {
  const [logs, setLogs] = useState<AuditLogEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedAction, setSelectedAction] = useState<string>("all");
  const [expandedLogId, setExpandedLogId] = useState<number | null>(null);

  const fetchLogs = async () => {
    setIsLoading(true);
    try {
      const data = await api.getAuditLogs();
      setLogs(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error("Failed to load audit logs:", err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs();
  }, []);

  const filteredLogs = logs.filter((log) => {
    const matchesAction = selectedAction === "all" || log.action === selectedAction;
    const searchLower = searchQuery.toLowerCase();
    const payloadStr = JSON.stringify(log.payload || {}).toLowerCase();
    const matchesSearch =
      log.action.toLowerCase().includes(searchLower) ||
      payloadStr.includes(searchLower) ||
      (log.ip_address && log.ip_address.toLowerCase().includes(searchLower));

    return matchesAction && matchesSearch;
  });

  return (
    <AppLayout>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <PageHeader
          title="Security & Compliance Audit Trail"
          subtitle="Immutable tamper-evident ledger tracking cryptographic QR rotations, refunds, overrides, and session closures"
        />
        <Button
          variant="outline"
          size="sm"
          onClick={fetchLogs}
          disabled={isLoading}
          className="gap-2 self-start sm:self-center"
        >
          <RefreshCw className={`w-4 h-4 ${isLoading ? "animate-spin" : ""}`} />
          Refresh Audit Trail
        </Button>
      </div>

      {/* Filter and Search Bar */}
      <div className="glass-card p-4 rounded-2xl border border-border/50 mb-6 flex flex-col md:flex-row items-center justify-between gap-4">
        <div className="flex flex-wrap gap-1.5 w-full md:w-auto">
          {[
            { key: "all", label: "All Events" },
            { key: "qr_token_regenerated", label: "QR Rotations" },
            { key: "table_session_settled", label: "Settlements" },
            { key: "order_cancelled", label: "Cancellations" },
            { key: "payment_refunded", label: "Refunds" },
            { key: "qr_ordering_toggled", label: "Kill-Switch" },
          ].map((tab) => (
            <button
              key={tab.key}
              onClick={() => setSelectedAction(tab.key)}
              className={`px-3 py-1.5 rounded-xl text-xs font-semibold transition-all ${
                selectedAction === tab.key
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "bg-muted/50 text-muted-foreground hover:text-foreground"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="relative w-full md:w-72">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search action, payload, table, IP..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-9 h-9 text-xs rounded-xl"
          />
        </div>
      </div>

      {/* Audit Log Entries List */}
      <div className="space-y-3">
        {filteredLogs.length === 0 ? (
          <div className="glass-card p-12 text-center rounded-2xl border border-border/50">
            <AlertCircle className="w-8 h-8 mx-auto mb-2 text-muted-foreground/40" />
            <p className="text-sm font-semibold text-foreground">No audit entries found</p>
            <p className="text-xs text-muted-foreground mt-1">
              Actions such as table QR regeneration, staff settlements, cancellations, and refunds will be recorded here automatically.
            </p>
          </div>
        ) : (
          filteredLogs.map((log) => {
            const badge = actionBadges[log.action] || {
              label: log.action.replace(/_/g, " "),
              color: "bg-muted text-muted-foreground border-border",
              icon: ShieldCheck,
            };
            const Icon = badge.icon;
            const isExpanded = expandedLogId === log.id;

            return (
              <motion.div
                key={log.id}
                layout
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                className="glass-card p-4 rounded-2xl border border-border/50 transition-all hover:border-primary/40 shadow-sm"
              >
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-primary/10 flex items-center justify-center text-primary shrink-0">
                      <Icon className="w-4 h-4" />
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <span className={`text-xs font-semibold px-2.5 py-0.5 rounded-full border ${badge.color}`}>
                          {badge.label}
                        </span>
                        <span className="font-mono text-[11px] text-muted-foreground">
                          Event #{log.id}
                        </span>
                      </div>
                      <p className="text-xs text-foreground font-medium mt-1">
                        {log.payload?.reason || log.payload?.notes || log.payload?.table_number || "System audit event executed"}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center justify-between sm:justify-end gap-3 text-xs text-muted-foreground pl-12 sm:pl-0">
                    <span className="font-mono text-[11px] bg-muted/60 px-2 py-0.5 rounded">
                      IP: {log.ip_address || "127.0.0.1"}
                    </span>
                    <span className="flex items-center gap-1">
                      <Clock className="w-3.5 h-3.5" />
                      {new Date(log.created_at).toLocaleString([], {
                        month: "short",
                        day: "numeric",
                        hour: "2-digit",
                        minute: "2-digit",
                      })}
                    </span>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setExpandedLogId(isExpanded ? null : log.id)}
                      className="h-7 text-xs px-2 text-primary"
                    >
                      {isExpanded ? "Hide Payload" : "Inspect Payload"}
                    </Button>
                  </div>
                </div>

                <AnimatePresence>
                  {isExpanded && (
                    <motion.div
                      initial={{ opacity: 0, height: 0 }}
                      animate={{ opacity: 1, height: "auto" }}
                      exit={{ opacity: 0, height: 0 }}
                      className="mt-3 pt-3 border-t border-border/40 overflow-hidden"
                    >
                      <div className="bg-muted/40 rounded-xl p-3 font-mono text-[11px] text-foreground overflow-x-auto border border-border/60">
                        <div className="flex items-center gap-1.5 text-muted-foreground mb-1.5 font-bold uppercase tracking-wider text-[10px]">
                          <Terminal className="w-3.5 h-3.5" /> Structured Event Payload
                        </div>
                        <pre>{JSON.stringify(log.payload, null, 2)}</pre>
                      </div>
                    </motion.div>
                  )}
                </AnimatePresence>
              </motion.div>
            );
          })
        )}
      </div>
    </AppLayout>
  );
}
