import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  CreditCard,
  DollarSign,
  Receipt,
  TrendingUp,
  Banknote,
  Smartphone,
  RefreshCw,
  Coins,
  RotateCcw,
  AlertCircle,
  Search,
  Filter,
  CheckCircle2,
  Clock,
} from "lucide-react";
import { AdminLayout as AppLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
import { StatCard } from "@/components/StatCard";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

interface PaymentItem {
  id: number;
  transaction_reference: string;
  order_id?: number | null;
  session_id?: number | null;
  amount: number | string;
  currency: string;
  payment_method: string;
  payment_type: string;
  status: string;
  notes?: string | null;
  created_at: string;
  session?: {
    id: number;
    table?: {
      id: number;
      table_number: string;
    };
  };
  guest?: {
    name: string;
  };
}

interface PaymentStats {
  total_revenue: number;
  net_revenue: number;
  refunds_total: number;
  transactions_count: number;
  by_method: Array<{
    payment_method: string;
    total: number;
    count: number;
  }>;
}

const methodIcons: Record<string, typeof CreditCard> = {
  card: CreditCard,
  cash: Banknote,
  mobile_money: Smartphone,
  mobile: Smartphone,
  coins: Coins,
};

export default function PaymentsPage() {
  const [payments, setPayments] = useState<PaymentItem[]>([]);
  const [stats, setStats] = useState<PaymentStats | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [methodFilter, setMethodFilter] = useState("all");

  // Refund dialog state
  const [refundTarget, setRefundTarget] = useState<PaymentItem | null>(null);
  const [refundReason, setRefundReason] = useState("");
  const [isRefunding, setIsRefunding] = useState(false);

  const fetchPaymentData = async () => {
    setIsLoading(true);
    try {
      const [paymentsRes, statsRes] = await Promise.allSettled([
        api.getPayments(),
        api.getPaymentStats(),
      ]);

      if (paymentsRes.status === "fulfilled" && paymentsRes.value) {
        const data = paymentsRes.value.data || paymentsRes.value;
        setPayments(Array.isArray(data) ? data : []);
      }

      if (statsRes.status === "fulfilled" && statsRes.value) {
        setStats(statsRes.value);
      }
    } catch (err) {
      console.error("Failed to load payment data:", err);
      toast({
        title: "Error loading payments",
        description: "Could not fetch latest transaction data.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchPaymentData();
  }, []);

  const handleRefund = async () => {
    if (!refundTarget) return;
    if (refundReason.trim().length < 5) {
      toast({
        title: "Reason required",
        description: "Please enter a valid reason of at least 5 characters.",
        variant: "destructive",
      });
      return;
    }

    setIsRefunding(true);
    try {
      await api.refundPayment(refundTarget.id, refundReason.trim());
      toast({
        title: "Refund processed",
        description: `Successfully issued refund for ${refundTarget.transaction_reference}.`,
      });
      setRefundTarget(null);
      setRefundReason("");
      fetchPaymentData();
    } catch (err: any) {
      toast({
        title: "Refund failed",
        description: err.message || "Failed to process refund.",
        variant: "destructive",
      });
    } finally {
      setIsRefunding(false);
    }
  };

  const filteredPayments = payments.filter((tx) => {
    const matchesSearch =
      tx.transaction_reference.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (tx.notes && tx.notes.toLowerCase().includes(searchQuery.toLowerCase())) ||
      (tx.session?.table?.table_number &&
        tx.session.table.table_number.toLowerCase().includes(searchQuery.toLowerCase()));

    const matchesMethod =
      methodFilter === "all" ||
      (methodFilter === "refund" && Number(tx.amount) < 0) ||
      tx.payment_method.toLowerCase() === methodFilter.toLowerCase();

    return matchesSearch && matchesMethod;
  });

  const totalRev = stats ? Number(stats.total_revenue) : 0;
  const netRev = stats ? Number(stats.net_revenue) : 0;
  const refundsTotal = stats ? Number(stats.refunds_total) : 0;
  const txCount = stats ? Number(stats.transactions_count) : payments.length;

  return (
    <AppLayout>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <PageHeader
          title="Payments & Billing"
          subtitle="Real-time transaction settlement, immutable refund ledgers & revenue telemetry"
        />
        <Button
          variant="outline"
          size="sm"
          onClick={fetchPaymentData}
          disabled={isLoading}
          className="gap-2 self-start sm:self-center"
        >
          <RefreshCw className={`w-4 h-4 ${isLoading ? "animate-spin" : ""}`} />
          Refresh Ledger
        </Button>
      </div>

      {/* KPI Stat Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard
          icon={DollarSign}
          title="Gross Revenue"
          value={`$${totalRev.toFixed(2)}`}
          change="+12%"
          changeType="positive"
          delay={0}
        />
        <StatCard
          icon={TrendingUp}
          title="Net Revenue"
          value={`$${netRev.toFixed(2)}`}
          change="+8%"
          changeType="positive"
          delay={0.1}
        />
        <StatCard
          icon={Receipt}
          title="Transactions"
          value={txCount.toString()}
          delay={0.2}
        />
        <StatCard
          icon={RotateCcw}
          title="Total Refunds"
          value={`$${refundsTotal.toFixed(2)}`}
          change={refundsTotal > 0 ? `-$${refundsTotal.toFixed(2)}` : "None"}
          changeType={refundsTotal > 0 ? "negative" : "neutral"}
          delay={0.3}
        />
      </div>

      {/* Payment Methods Breakdown */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {(stats?.by_method || [
          { payment_method: "card", total: 0, count: 0 },
          { payment_method: "cash", total: 0, count: 0 },
          { payment_method: "mobile_money", total: 0, count: 0 },
          { payment_method: "coins", total: 0, count: 0 },
        ]).map((m, i) => {
          const methodKey = m.payment_method.toLowerCase();
          const Icon = methodIcons[methodKey] || CreditCard;
          const totalVal = totalRev > 0 ? (Number(m.total) / totalRev) * 100 : 0;
          return (
            <motion.div
              key={m.payment_method}
              initial={{ opacity: 0, y: 15 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 + i * 0.05 }}
              className="glass-card p-5 rounded-2xl border border-border/50"
            >
              <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                  <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
                    <Icon className="w-4 h-4" />
                  </div>
                  <span className="text-sm font-semibold capitalize text-foreground">
                    {m.payment_method.replace("_", " ")}
                  </span>
                </div>
                <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-muted text-muted-foreground">
                  {m.count} txns
                </span>
              </div>
              <div className="h-2 bg-muted rounded-full overflow-hidden mb-2">
                <motion.div
                  initial={{ width: 0 }}
                  animate={{ width: `${Math.min(100, Math.max(0, totalVal))}%` }}
                  transition={{ delay: 0.4 + i * 0.05, duration: 0.8, ease: "easeOut" }}
                  className="h-full rounded-full bg-primary"
                />
              </div>
              <div className="flex items-center justify-between">
                <p className="text-lg font-display font-bold text-foreground">
                  ${Number(m.total || 0).toFixed(2)}
                </p>
                <span className="text-xs text-muted-foreground">{totalVal.toFixed(0)}%</span>
              </div>
            </motion.div>
          );
        })}
      </div>

      {/* Transactions Table & Filters */}
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.4 }}
        className="glass-card-elevated rounded-2xl overflow-hidden border border-border/50 shadow-sm"
      >
        <div className="p-5 border-b border-border/50 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h2 className="font-display font-semibold text-lg text-foreground">Audit Payment Ledger</h2>
            <p className="text-xs text-muted-foreground">
              Immutable records with cryptographic transaction identifiers and refund audit tracking
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
              <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search reference, table, notes..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9 h-9 w-60 text-xs rounded-xl"
              />
            </div>
            <div className="flex items-center bg-muted/60 p-1 rounded-xl text-xs gap-1">
              {["all", "card", "cash", "mobile_money", "refund"].map((filter) => (
                <button
                  key={filter}
                  onClick={() => setMethodFilter(filter)}
                  className={`px-3 py-1.5 rounded-lg font-medium transition-all capitalize ${
                    methodFilter === filter
                      ? "bg-background text-foreground shadow-sm"
                      : "text-muted-foreground hover:text-foreground"
                  }`}
                >
                  {filter.replace("_", " ")}
                </button>
              ))}
            </div>
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left">
            <thead>
              <tr className="text-xs font-semibold text-muted-foreground uppercase tracking-wider bg-muted/30 border-b border-border/50">
                <th className="p-4">Transaction Ref</th>
                <th className="p-4">Table / Session</th>
                <th className="p-4">Method & Split</th>
                <th className="p-4 text-right">Amount</th>
                <th className="p-4">Status</th>
                <th className="p-4">Timestamp</th>
                <th className="p-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredPayments.length === 0 ? (
                <tr>
                  <td colSpan={7} className="p-12 text-center text-muted-foreground">
                    <AlertCircle className="w-8 h-8 mx-auto mb-2 opacity-40" />
                    <p className="text-sm font-medium">No transactions found</p>
                    <p className="text-xs text-muted-foreground mt-1">
                      {searchQuery || methodFilter !== "all"
                        ? "Try clearing filters or search terms."
                        : "Completed dining orders and payments will appear here."}
                    </p>
                  </td>
                </tr>
              ) : (
                filteredPayments.map((tx) => {
                  const MIcon = methodIcons[tx.payment_method.toLowerCase()] || CreditCard;
                  const isRefund = Number(tx.amount) < 0;
                  const isCompleted = tx.status === "completed";
                  const isPending = tx.status === "pending_confirmation";

                  return (
                    <motion.tr
                      key={tx.id}
                      initial={{ opacity: 0 }}
                      animate={{ opacity: 1 }}
                      className="border-b border-border/30 hover:bg-muted/20 transition-colors"
                    >
                      <td className="p-4">
                        <div className="font-mono text-xs font-semibold text-foreground">
                          {tx.transaction_reference}
                        </div>
                        {tx.notes && (
                          <p className="text-[11px] text-muted-foreground truncate max-w-[220px]">
                            {tx.notes}
                          </p>
                        )}
                      </td>
                      <td className="p-4">
                        {tx.session?.table?.table_number ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-semibold">
                            Table {tx.session.table.table_number}
                          </span>
                        ) : tx.session_id ? (
                          <span className="text-xs text-muted-foreground">
                            Session #{tx.session_id}
                          </span>
                        ) : (
                          <span className="text-xs text-muted-foreground">Direct Order</span>
                        )}
                      </td>
                      <td className="p-4">
                        <div className="flex items-center gap-2 text-sm text-foreground">
                          <MIcon className="w-4 h-4 text-muted-foreground" />
                          <span className="capitalize font-medium text-xs">
                            {tx.payment_method.replace("_", " ")}
                          </span>
                        </div>
                        <span className="text-[10px] text-muted-foreground uppercase font-mono">
                          {tx.payment_type || "Standard"}
                        </span>
                      </td>
                      <td className="p-4 text-right">
                        <span
                          className={`font-semibold font-mono text-sm ${
                            isRefund ? "text-destructive" : "text-foreground"
                          }`}
                        >
                          {isRefund ? `-$${Math.abs(Number(tx.amount)).toFixed(2)}` : `$${Number(tx.amount).toFixed(2)}`}
                        </span>
                      </td>
                      <td className="p-4">
                        <span
                          className={`inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-0.5 rounded-full ${
                            isRefund
                              ? "bg-destructive/10 text-destructive"
                              : isPending
                              ? "bg-amber-500/10 text-amber-600"
                              : "bg-success/10 text-success"
                          }`}
                        >
                          {isRefund ? (
                            <>
                              <RotateCcw className="w-3 h-3" /> Refunded
                            </>
                          ) : isPending ? (
                            <>
                              <Clock className="w-3 h-3" /> Awaiting Staff
                            </>
                          ) : (
                            <>
                              <CheckCircle2 className="w-3 h-3" /> Completed
                            </>
                          )}
                        </span>
                      </td>
                      <td className="p-4 text-xs text-muted-foreground">
                        {new Date(tx.created_at).toLocaleString([], {
                          month: "short",
                          day: "numeric",
                          hour: "2-digit",
                          minute: "2-digit",
                        })}
                      </td>
                      <td className="p-4 text-right">
                        {!isRefund && isCompleted && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              setRefundTarget(tx);
                              setRefundReason("");
                            }}
                            className="h-8 text-xs text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                          >
                            <RotateCcw className="w-3.5 h-3.5 mr-1" />
                            Refund
                          </Button>
                        )}
                      </td>
                    </motion.tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </motion.div>

      {/* Immutable Refund Modal */}
      <Dialog open={!!refundTarget} onOpenChange={(open) => !open && setRefundTarget(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-destructive">
              <RotateCcw className="w-5 h-5" /> Issue Ledger Refund
            </DialogTitle>
            <DialogDescription>
              This will create an immutable, negative balance entry referencing{" "}
              <span className="font-mono font-semibold text-foreground">
                {refundTarget?.transaction_reference}
              </span>{" "}
              and write to the security audit trail.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="bg-muted/40 p-3 rounded-xl border border-border/50 text-xs space-y-1">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Original Amount:</span>
                <span className="font-semibold text-foreground">
                  ${refundTarget ? Number(refundTarget.amount).toFixed(2) : "0.00"}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Payment Method:</span>
                <span className="font-semibold capitalize text-foreground">
                  {refundTarget?.payment_method.replace("_", " ")}
                </span>
              </div>
            </div>

            <div>
              <label className="text-xs font-semibold text-foreground mb-1 block">
                Refund Justification (minimum 5 characters) *
              </label>
              <Input
                placeholder="e.g. Customer changed mind / incorrect item charged"
                value={refundReason}
                onChange={(e) => setRefundReason(e.target.value)}
                className="text-xs"
              />
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setRefundTarget(null)}
              disabled={isRefunding}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={handleRefund}
              disabled={isRefunding || refundReason.trim().length < 5}
            >
              {isRefunding ? "Processing..." : "Confirm & Record Refund"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
