import React, { useEffect, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { motion, AnimatePresence } from "framer-motion";
import { 
  ArrowLeft, 
  Receipt, 
  Clock, 
  CheckCircle2, 
  Flame, 
  UtensilsCrossed, 
  BellRing, 
  CreditCard, 
  Users, 
  RefreshCw,
  ChefHat,
  Sparkles,
  Split,
  ChevronDown,
  ChevronUp,
  XCircle,
  CheckSquare,
  Square,
  Star
} from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface OrderItem {
  id: number;
  item_name: string;
  unit_price: string;
  quantity: number;
  subtotal: string;
  status: string;
  special_notes?: string;
  customizations?: string[];
}

interface OrderData {
  id: number;
  order_number: string;
  guest_name: string;
  status: "placed" | "accepted" | "preparing" | "ready" | "served" | "completed" | "cancelled";
  subtotal: string;
  tax: string;
  service_charge: string;
  total: string;
  payment_choice: string;
  created_at: string;
  items: OrderItem[];
}

interface TableTabState {
  table: {
    table_number: string;
    zone: string;
  };
  branch: {
    name: string;
    currency: string;
    tax_rate: string;
    service_charge: string;
  };
  session: {
    id: number;
    status: string;
    opened_at: string;
    subtotal: string;
    tax: string;
    service_charge: string;
    total: string;
    paid_amount: string;
    balance_due: number;
  };
  guests: any[];
  orders: OrderData[];
}

export default function QrTableTab() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [data, setData] = useState<TableTabState | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Bill Splitting Mode: 'full' | 'equal' | 'item'
  const [splitMode, setSplitMode] = useState<"full" | "equal" | "item">("full");
  const [splitCount, setSplitCount] = useState(2);
  const [selectedItemIds, setSelectedItemIds] = useState<Set<number>>(new Set());

  // Cancel order modal
  const [cancellingOrderId, setCancellingOrderId] = useState<number | null>(null);
  const [cancelling, setCancelling] = useState(false);

  // Call waiter state
  const [callingWaiter, setCallingWaiter] = useState(false);
  const [requestingBill, setRequestingBill] = useState(false);
  const [paying, setPaying] = useState(false);

  // Rate the order review state
  const [reviewModalOpen, setReviewModalOpen] = useState(false);
  const [reviewRating, setReviewRating] = useState(5);
  const [reviewComment, setReviewComment] = useState("");
  const [reviewSubmitted, setReviewSubmitted] = useState(false);

  const handleSubmitReview = (e: React.FormEvent) => {
    e.preventDefault();
    setReviewSubmitted(true);
    setReviewModalOpen(false);
    toast({
      title: "Thank You for Your Feedback!",
      description: `Your ${reviewRating}-star review was shared with the chef and floor manager.`,
    });
  };

  const handlePayNow = async () => {
    if (!token || !data) return;
    try {
      setPaying(true);
      const amt = splitMode === "item" 
        ? parseFloat(selectedItemsTotal)
        : splitMode === "equal"
        ? baseEqualShare
        : sessionTotal;

      if (amt <= 0) return;

      const res: any = await api.payCustomerBill(token, {
        amount: amt,
        payment_method: "card",
        split_type: splitMode,
      });

      toast({
        title: res.is_fully_paid ? "Bill Paid in Full! 🎉" : "Payment Received",
        description: res.message || "Thank you for dining with Café X.",
      });

      fetchTab(true);
    } catch (err: any) {
      toast({
        title: "Payment Failed",
        description: err.message || "Could not process payment.",
        variant: "destructive",
      });
    } finally {
      setPaying(false);
    }
  };

  const fetchTab = async (isManual = false) => {
    if (!token) return;
    try {
      if (isManual) setRefreshing(true);
      const res = await api.getTableTab(token);
      setData(res);
      setError(null);
    } catch (err: any) {
      console.error("Failed to load table tab:", err);
      setError(err.message || "Failed to load table tab.");
    } finally {
      setLoading(false);
      if (isManual) setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchTab();

    // Visibility-aware smart polling: pauses when backgrounded, resumes when visible
    let interval: NodeJS.Timeout | null = null;

    const startPolling = () => {
      if (!interval) {
        interval = setInterval(() => {
          if (!document.hidden) {
            fetchTab();
          }
        }, 5000);
      }
    };

    const stopPolling = () => {
      if (interval) {
        clearInterval(interval);
        interval = null;
      }
    };

    const handleVisibility = () => {
      if (document.hidden) {
        stopPolling();
      } else {
        fetchTab(true);
        startPolling();
      }
    };

    startPolling();
    document.addEventListener("visibilitychange", handleVisibility);

    return () => {
      stopPolling();
      document.removeEventListener("visibilitychange", handleVisibility);
    };
  }, [token]);

  const handleCallWaiter = async () => {
    if (!token) return;
    try {
      setCallingWaiter(true);
      await api.callWaiter(token, {
        guest_name: localStorage.getItem("cafex_guest_name") || "Guest",
        reason: "assistance",
      });
      toast({
        title: "Waiter Alerted",
        description: `Floor team notified for ${data?.table.table_number || "your table"}.`,
      });
    } catch (err: any) {
      toast({
        title: "Call Failed",
        description: err.message || "Could not call waiter.",
        variant: "destructive",
      });
    } finally {
      setCallingWaiter(false);
    }
  };

  const handleRequestBill = async () => {
    if (!token) return;
    try {
      setRequestingBill(true);
      await api.requestBill(token, {
        guest_name: localStorage.getItem("cafex_guest_name") || "Guest",
        split_type: splitMode,
      });
      toast({
        title: "Bill Requested",
        description: "Your server is bringing the bill and portable card terminal.",
      });
      fetchTab(true);
    } catch (err: any) {
      toast({
        title: "Request Failed",
        description: err.message || "Could not request bill.",
        variant: "destructive",
      });
    } finally {
      setRequestingBill(false);
    }
  };

  const handleCancelOrder = async (orderId: number) => {
    if (!token) return;
    try {
      setCancelling(true);
      await api.cancelCustomerOrder(token, orderId, "Cancelled by diner");
      toast({
        title: "Order Cancelled",
        description: `Order #${orderId} has been cancelled.`,
      });
      setCancellingOrderId(null);
      fetchTab(true);
    } catch (err: any) {
      toast({
        title: "Cannot Cancel",
        description: err.message || "Order is already being prepared by the kitchen.",
        variant: "destructive",
      });
    } finally {
      setCancelling(false);
    }
  };

  const toggleItemSelection = (itemId: number) => {
    setSelectedItemIds((prev) => {
      const next = new Set(prev);
      if (next.has(itemId)) {
        next.delete(itemId);
      } else {
        next.add(itemId);
      }
      return next;
    });
  };

  const getStatusStep = (status: string) => {
    const s = status.toLowerCase();
    if (s === "served" || s === "completed") return 4;
    if (s === "ready") return 3;
    if (s === "preparing") return 2;
    if (s === "accepted") return 1;
    return 0; // placed
  };

  const getStatusBadge = (status: string) => {
    const s = status.toLowerCase();
    switch (s) {
      case "placed":
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-stone-800 text-stone-300 border border-stone-700">Order Sent</span>;
      case "accepted":
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/30 flex items-center gap-1">Kitchen Accepted</span>;
      case "preparing":
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/30 flex items-center gap-1"><Flame className="w-3 h-3 animate-bounce" /> In Preparation</span>;
      case "ready":
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 flex items-center gap-1"><ChefHat className="w-3 h-3" /> Ready for Serving</span>;
      case "served":
      case "completed":
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1"><CheckCircle2 className="w-3 h-3" /> Served</span>;
      case "cancelled":
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/15 text-red-400 border border-red-500/30">Cancelled</span>;
      default:
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-stone-800 text-stone-400">{status}</span>;
    }
  };

  const currencySymbol = data?.branch?.currency === "EUR" ? "€" : "$";

  if (loading) {
    return (
      <div className="min-h-screen bg-stone-950 text-stone-100 flex flex-col items-center justify-center p-6">
        <motion.div
          animate={{ rotate: 360 }}
          transition={{ repeat: Infinity, duration: 1.2, ease: "linear" }}
          className="w-10 h-10 border-4 border-amber-500 border-t-transparent rounded-full mb-3"
        />
        <p className="text-stone-400 text-sm">Loading table tab & kitchen status...</p>
      </div>
    );
  }

  if (error || !data || data.session?.status === 'closed') {
    return (
      <div className="min-h-screen bg-stone-950 text-stone-100 flex flex-col items-center justify-center p-6 text-center">
        <Receipt className="w-12 h-12 text-stone-600 mb-3" />
        <h2 className="text-lg font-bold mb-2">
          {data?.session?.status === 'closed' ? "Dining Session Closed" : "Could Not Load Table Tab"}
        </h2>
        <p className="text-stone-400 text-xs mb-5 max-w-xs">
          {data?.session?.status === 'closed' 
            ? "This table tab has been fully settled and closed. Previous guest secrets have expired. Scan the table QR code to start a fresh dining tab."
            : error}
        </p>
        {data?.session?.status === 'closed' ? (
          <Link
            to={`/t/${token}`}
            className="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-stone-950 font-bold rounded-xl text-xs transition-colors"
          >
            Scan Table QR Again
          </Link>
        ) : (
          <button
            onClick={() => fetchTab(true)}
            className="px-5 py-2 bg-amber-500 text-stone-950 font-bold rounded-xl text-xs"
          >
            Retry
          </button>
        )}
      </div>
    );
  }

  const sessionTotal = Number(data.session?.total || 0);
  const baseEqualShare = Math.floor((sessionTotal / Math.max(1, splitCount)) * 100) / 100;
  const remainder = Math.round((sessionTotal - baseEqualShare * splitCount) * 100) / 100;

  // Split by item calculation
  const allOrderItems: Array<{ item: OrderItem; orderGuest: string }> = [];
  data.orders
    .filter((o) => o.status !== "cancelled")
    .forEach((o) => {
      o.items.forEach((it) => {
        allOrderItems.push({ item: it, orderGuest: o.guest_name });
      });
    });

  const selectedItemsSubtotal = allOrderItems
    .filter((entry) => selectedItemIds.has(entry.item.id))
    .reduce((sum, entry) => sum + Number(entry.item.subtotal), 0);

  const taxRateNum = parseFloat(data.branch.tax_rate) / 100 || 0.1;
  const serviceRateNum = parseFloat(data.branch.service_charge) / 100 || 0.05;
  const selectedItemsTax = Math.round(selectedItemsSubtotal * taxRateNum * 100) / 100;
  const selectedItemsService = Math.round(selectedItemsSubtotal * serviceRateNum * 100) / 100;
  const selectedItemsTotal = (selectedItemsSubtotal + selectedItemsTax + selectedItemsService).toFixed(2);

  return (
    <div className="min-h-screen bg-stone-950 text-stone-100 flex flex-col pb-28">
      {/* Top Header */}
      <header className="border-b border-stone-800/80 bg-stone-900/60 backdrop-blur-md sticky top-0 z-20 px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <button
            onClick={() => navigate(`/t/${token}`)}
            className="w-8 h-8 rounded-lg bg-stone-800/80 hover:bg-stone-700 flex items-center justify-center text-stone-300 transition-colors"
          >
            <ArrowLeft className="w-4 h-4" />
          </button>
          <div>
            <h1 className="font-bold text-sm text-stone-100 leading-tight">
              {data.table.table_number} Running Tab
            </h1>
            <p className="text-[11px] text-stone-400">{data.branch.name}</p>
          </div>
        </div>

        <button
          onClick={() => fetchTab(true)}
          disabled={refreshing}
          className="p-2 rounded-lg bg-stone-800/80 hover:bg-stone-700 text-stone-300 transition-colors disabled:opacity-50"
          title="Refresh"
        >
          <RefreshCw className={`w-4 h-4 ${refreshing ? "animate-spin text-amber-400" : ""}`} />
        </button>
      </header>

      {/* Main Tab Container */}
      <main className="flex-1 max-w-md w-full mx-auto p-4 flex flex-col gap-4">
        {/* Bill Summary Card */}
        <div className="rounded-3xl bg-gradient-to-br from-stone-900 via-stone-900 to-stone-950 border border-stone-800 p-5 shadow-xl relative overflow-hidden">
          <div className="flex items-center justify-between mb-2">
            <span className="text-xs uppercase font-bold text-amber-400 flex items-center gap-1.5">
              <Receipt className="w-3.5 h-3.5" /> Shared Tab Balance
            </span>
            <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full ${
              data.session.status === 'billing' ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-500/15 text-emerald-400'
            }`}>
              {data.session.status === 'billing' ? 'Bill Requested' : 'Active Session'}
            </span>
          </div>

          <div className="flex items-baseline justify-between mb-4">
            <div>
              <span className="text-3xl font-black text-stone-100 tracking-tight">
                {currencySymbol}{sessionTotal.toFixed(2)}
              </span>
              <p className="text-xs text-stone-400 mt-0.5">
                {data.orders.filter(o => o.status !== 'cancelled').length} orders placed at this table
              </p>
            </div>

            {/* Split Bill Options Selector */}
            <div className="flex items-center gap-1 bg-stone-950 border border-stone-800 rounded-xl p-1 text-[11px]">
              <button
                onClick={() => setSplitMode("full")}
                className={`px-2.5 py-1 rounded-lg font-medium transition-colors ${
                  splitMode === "full" ? "bg-amber-500 text-stone-950 font-bold" : "text-stone-400 hover:text-stone-200"
                }`}
              >
                Full
              </button>
              <button
                onClick={() => setSplitMode("equal")}
                className={`px-2.5 py-1 rounded-lg font-medium transition-colors ${
                  splitMode === "equal" ? "bg-amber-500 text-stone-950 font-bold" : "text-stone-400 hover:text-stone-200"
                }`}
              >
                Equal
              </button>
              <button
                onClick={() => setSplitMode("item")}
                className={`px-2.5 py-1 rounded-lg font-medium transition-colors ${
                  splitMode === "item" ? "bg-amber-500 text-stone-950 font-bold" : "text-stone-400 hover:text-stone-200"
                }`}
              >
                By Item
              </button>
            </div>
          </div>

          {/* Equal Split Calculator */}
          {splitMode === "equal" && (
            <motion.div
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: "auto" }}
              className="bg-stone-950/80 border border-stone-800 rounded-2xl p-3.5 mb-4 text-xs space-y-2"
            >
              <div className="flex items-center justify-between">
                <span className="text-stone-300 font-medium">Split equally between:</span>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setSplitCount(Math.max(2, splitCount - 1))}
                    className="w-6 h-6 rounded-md bg-stone-800 hover:bg-stone-700 text-stone-200 font-bold flex items-center justify-center"
                  >
                    -
                  </button>
                  <span className="font-bold text-amber-400">{splitCount} guests</span>
                  <button
                    onClick={() => setSplitCount(splitCount + 1)}
                    className="w-6 h-6 rounded-md bg-stone-800 hover:bg-stone-700 text-stone-200 font-bold flex items-center justify-center"
                  >
                    +
                  </button>
                </div>
              </div>
              <div className="flex justify-between border-t border-stone-800/80 pt-2 font-bold text-stone-200">
                <span>Per person:</span>
                <span className="text-amber-400 text-sm">{currencySymbol}{baseEqualShare.toFixed(2)}</span>
              </div>
              {remainder > 0 && (
                <p className="text-[10px] text-stone-500 italic">
                  *Last diner absorbs remaining {currencySymbol}{remainder.toFixed(2)} cent roundoff.
                </p>
              )}
            </motion.div>
          )}

          {/* Split by Item Calculator */}
          {splitMode === "item" && (
            <motion.div
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: "auto" }}
              className="bg-stone-950/80 border border-stone-800 rounded-2xl p-3.5 mb-4 text-xs space-y-2.5"
            >
              <div className="flex items-center justify-between">
                <span className="text-stone-300 font-bold">Select dishes you ordered:</span>
                <button
                  onClick={() => {
                    if (selectedItemIds.size === allOrderItems.length) {
                      setSelectedItemIds(new Set());
                    } else {
                      setSelectedItemIds(new Set(allOrderItems.map((entry) => entry.item.id)));
                    }
                  }}
                  className="text-[10px] text-amber-400 hover:underline"
                >
                  {selectedItemIds.size === allOrderItems.length ? "Deselect All" : "Select All"}
                </button>
              </div>

              <div className="max-h-36 overflow-y-auto space-y-1.5 divide-y divide-stone-800/60 pr-1">
                {allOrderItems.map(({ item, orderGuest }) => {
                  const isChecked = selectedItemIds.has(item.id);
                  return (
                    <div
                      key={item.id}
                      onClick={() => toggleItemSelection(item.id)}
                      className="pt-1.5 first:pt-0 flex items-center justify-between cursor-pointer group"
                    >
                      <div className="flex items-center gap-2">
                        {isChecked ? (
                          <CheckSquare className="w-4 h-4 text-amber-400 flex-shrink-0" />
                        ) : (
                          <Square className="w-4 h-4 text-stone-600 group-hover:text-stone-400 flex-shrink-0" />
                        )}
                        <span className={`text-xs ${isChecked ? "text-stone-100 font-semibold" : "text-stone-400"}`}>
                          {item.quantity}x {item.item_name}
                        </span>
                      </div>
                      <span className="text-xs text-stone-300 font-medium">
                        {currencySymbol}{Number(item.subtotal).toFixed(2)}
                      </span>
                    </div>
                  );
                })}
              </div>

              <div className="border-t border-stone-800 pt-2 flex justify-between font-bold text-stone-200">
                <span>Your Selected Share (incl. tax & svc):</span>
                <span className="text-amber-400 text-sm">{currencySymbol}{selectedItemsTotal}</span>
              </div>
            </motion.div>
          )}

          {/* Breakdown Lines */}
          <div className="space-y-1.5 border-t border-stone-800/80 pt-3 text-xs text-stone-400">
            <div className="flex justify-between">
              <span>Food & Drinks Subtotal</span>
              <span className="text-stone-200">{currencySymbol}{Number(data.session.subtotal).toFixed(2)}</span>
            </div>
            <div className="flex justify-between">
              <span>VAT / Tax ({data.branch.tax_rate}%)</span>
              <span className="text-stone-200">{currencySymbol}{Number(data.session.tax).toFixed(2)}</span>
            </div>
            <div className="flex justify-between">
              <span>Service Charge ({data.branch.service_charge}%)</span>
              <span className="text-stone-200">{currencySymbol}{Number(data.session.service_charge).toFixed(2)}</span>
            </div>
          </div>

          {/* Pay Now Button */}
          {sessionTotal > 0 && (
            <div className="pt-4 border-t border-stone-800">
              <button
                disabled={paying}
                onClick={handlePayNow}
                className="w-full py-3 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-stone-950 font-black rounded-xl text-xs flex items-center justify-center gap-2 shadow-lg transition-all disabled:opacity-50"
              >
                <CreditCard className="w-4 h-4" />
                {paying
                  ? "Processing..."
                  : `Pay Now (${currencySymbol}${
                      splitMode === "item"
                        ? selectedItemsTotal
                        : splitMode === "equal"
                        ? baseEqualShare.toFixed(2)
                        : sessionTotal.toFixed(2)
                    })`}
              </button>
            </div>
          )}
        </div>

        {/* Live Orders Tracker Section */}
        <div className="flex items-center justify-between px-1">
          <h2 className="text-sm font-bold text-stone-200 flex items-center gap-1.5">
            <UtensilsCrossed className="w-4 h-4 text-amber-400" /> Orders for Table
          </h2>
          {data.orders.length > 0 && (
            <button
              onClick={() => setReviewModalOpen(true)}
              className="text-[11px] font-semibold text-amber-400 hover:text-amber-300 flex items-center gap-1 bg-amber-500/10 px-2.5 py-1 rounded-lg border border-amber-500/20 transition-colors"
            >
              <Star className="w-3 h-3 fill-amber-400" /> {reviewSubmitted ? "Reviewed" : "Rate Meal"}
            </button>
          )}
        </div>

        {data.orders.length === 0 ? (
          <div className="bg-stone-900/40 border border-stone-800/80 rounded-2xl p-6 text-center">
            <UtensilsCrossed className="w-8 h-8 text-stone-600 mx-auto mb-2" />
            <p className="text-stone-300 font-medium text-xs mb-1">No orders placed yet</p>
            <p className="text-stone-500 text-[11px] mb-4">
              Explore the menu and order dishes to get started!
            </p>
            <Link
              to={`/customer/menu?token=${token}&table=${encodeURIComponent(data.table.table_number)}`}
              className="inline-flex items-center gap-1.5 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-stone-950 font-bold rounded-xl text-xs"
            >
              Order Dishes
            </Link>
          </div>
        ) : (
          <div className="space-y-3">
            {data.orders.map((order) => {
              const step = getStatusStep(order.status);
              const isPlacedOnly = order.status === "placed";

              return (
                <div
                  key={order.id}
                  className={`bg-stone-900/80 border rounded-2xl p-4 shadow-sm ${
                    order.status === "cancelled" ? "opacity-60 border-stone-800" : "border-stone-800"
                  }`}
                >
                  {/* Order Header */}
                  <div className="flex items-center justify-between mb-3">
                    <div className="flex items-center gap-2">
                      <div className="w-7 h-7 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 font-bold flex items-center justify-center text-xs">
                        {order.guest_name ? order.guest_name[0].toUpperCase() : "G"}
                      </div>
                      <div>
                        <span className="text-xs font-bold text-stone-200">
                          {order.guest_name || "Guest"}
                        </span>
                        <span className="text-[10px] text-stone-500 ml-1.5">
                          {order.order_number}
                        </span>
                      </div>
                    </div>
                    {getStatusBadge(order.status)}
                  </div>

                  {/* Visual Progress Stepper (if not cancelled) */}
                  {order.status !== "cancelled" && (
                    <div className="bg-stone-950/60 rounded-xl p-2.5 mb-3">
                      <div className="grid grid-cols-4 gap-1 text-center text-[10px]">
                        <div className={`py-1 rounded ${step >= 0 ? "bg-amber-500/20 text-amber-300 font-bold" : "text-stone-600"}`}>
                          Placed
                        </div>
                        <div className={`py-1 rounded ${step >= 1 ? "bg-amber-500/20 text-amber-300 font-bold" : "text-stone-600"}`}>
                          Accepted
                        </div>
                        <div className={`py-1 rounded ${step >= 2 ? "bg-amber-500/20 text-amber-300 font-bold" : "text-stone-600"}`}>
                          Preparing
                        </div>
                        <div className={`py-1 rounded ${step >= 3 ? "bg-emerald-500/20 text-emerald-300 font-bold" : "text-stone-600"}`}>
                          Served
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Order Items List */}
                  <div className="space-y-2 divide-y divide-stone-800/60 text-xs">
                    {order.items.map((item) => (
                      <div key={item.id} className="pt-2 first:pt-0">
                        <div className="flex items-center justify-between">
                          <span className="font-medium text-stone-200">
                            {item.quantity}x {item.item_name}
                          </span>
                          <span className="font-semibold text-stone-300">
                            {currencySymbol}{Number(item.subtotal).toFixed(2)}
                          </span>
                        </div>
                        {item.special_notes && (
                          <p className="text-[11px] text-amber-400/90 italic mt-0.5">
                            Note: "{item.special_notes}"
                          </p>
                        )}
                      </div>
                    ))}
                  </div>

                  {/* Order Footer & Actions */}
                  <div className="flex justify-between items-center border-t border-stone-800 pt-2.5 mt-3 text-xs">
                    <div>
                      {isPlacedOnly && (
                        <button
                          onClick={() => setCancellingOrderId(order.id)}
                          className="text-[11px] font-semibold text-red-400 hover:text-red-300 flex items-center gap-1"
                        >
                          <XCircle className="w-3 h-3" /> Cancel Order
                        </button>
                      )}
                    </div>
                    <div className="text-right">
                      <span className="text-stone-400 text-[11px] mr-1.5">Subtotal:</span>
                      <span className="font-bold text-stone-100">
                        {currencySymbol}{Number(order.total).toFixed(2)}
                      </span>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </main>

      {/* Floating Bottom Action Bar */}
      <div className="fixed bottom-0 inset-x-0 bg-stone-900/90 backdrop-blur-lg border-t border-stone-800 p-3 z-30">
        <div className="max-w-md mx-auto grid grid-cols-3 gap-2">
          {/* Order More */}
          <Link
            to={`/customer/menu?token=${token}&table=${encodeURIComponent(data.table.table_number)}`}
            className="col-span-1 bg-amber-500 hover:bg-amber-600 text-stone-950 font-bold py-2.5 px-3 rounded-xl text-xs flex items-center justify-center gap-1.5 shadow-md transition-colors"
          >
            <UtensilsCrossed className="w-3.5 h-3.5" /> Order More
          </Link>

          {/* Call Waiter */}
          <button
            onClick={handleCallWaiter}
            disabled={callingWaiter}
            className="bg-stone-800 hover:bg-stone-750 text-stone-200 font-semibold py-2.5 px-3 rounded-xl text-xs flex items-center justify-center gap-1.5 border border-stone-700 transition-colors disabled:opacity-50"
          >
            <BellRing className="w-3.5 h-3.5 text-blue-400" />
            {callingWaiter ? "Calling..." : "Call Waiter"}
          </button>

          {/* Request Bill */}
          <button
            onClick={handleRequestBill}
            disabled={requestingBill || data.session.status === 'billing'}
            className="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-3 rounded-xl text-xs flex items-center justify-center gap-1.5 shadow-md transition-colors disabled:opacity-60"
          >
            <CreditCard className="w-3.5 h-3.5" />
            {data.session.status === 'billing' ? 'Bill Pending' : 'Request Bill'}
          </button>
        </div>
      </div>

      {/* Cancel Confirmation Modal */}
      <AnimatePresence>
        {cancellingOrderId && (
          <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-stone-900 border border-stone-800 rounded-3xl max-w-sm w-full p-5 shadow-2xl text-center"
            >
              <div className="w-12 h-12 rounded-full bg-red-500/15 border border-red-500/30 text-red-400 flex items-center justify-center mx-auto mb-3">
                <XCircle className="w-6 h-6" />
              </div>
              <h3 className="font-bold text-base text-stone-100 mb-1">Cancel Order?</h3>
              <p className="text-xs text-stone-400 mb-5">
                Orders can only be cancelled while still in "Placed" state before the kitchen begins preparing.
              </p>

              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setCancellingOrderId(null)}
                  className="flex-1 py-2.5 bg-stone-800 hover:bg-stone-700 text-stone-300 font-semibold rounded-xl text-xs"
                >
                  Keep Order
                </button>
                <button
                  type="button"
                  disabled={cancelling}
                  onClick={() => handleCancelOrder(cancellingOrderId)}
                  className="flex-1 py-2.5 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl text-xs shadow disabled:opacity-50"
                >
                  {cancelling ? "Cancelling..." : "Confirm Cancel"}
                </button>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* Rate Your Meal Modal */}
      <AnimatePresence>
        {reviewModalOpen && (
          <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-stone-900 border border-stone-800 rounded-3xl max-w-sm w-full p-5 shadow-2xl relative text-center"
            >
              <button
                onClick={() => setReviewModalOpen(false)}
                className="absolute top-4 right-4 text-stone-400 hover:text-stone-200"
              >
                <XCircle className="w-5 h-5" />
              </button>

              <div className="w-12 h-12 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-400 flex items-center justify-center mx-auto mb-3">
                <Star className="w-6 h-6 fill-amber-400" />
              </div>

              <h3 className="font-bold text-base text-stone-100 mb-1">Rate Your Dining Experience</h3>
              <p className="text-xs text-stone-400 mb-4">
                How was your meal at {data.table.table_number}? Your rating helps our kitchen brigade and service team.
              </p>

              <form onSubmit={handleSubmitReview} className="space-y-4">
                {/* 5-Star Selector */}
                <div className="flex justify-center gap-2">
                  {[1, 2, 3, 4, 5].map((star) => (
                    <button
                      key={star}
                      type="button"
                      onClick={() => setReviewRating(star)}
                      className="p-1 transition-transform hover:scale-125"
                    >
                      <Star
                        className={`w-7 h-7 ${
                          star <= reviewRating
                            ? "text-amber-400 fill-amber-400"
                            : "text-stone-700"
                        }`}
                      />
                    </button>
                  ))}
                </div>

                <textarea
                  value={reviewComment}
                  onChange={(e) => setReviewComment(e.target.value)}
                  placeholder="Share any compliments or notes for the chef... (optional)"
                  className="w-full bg-stone-950/80 border border-stone-800 rounded-xl p-2.5 text-xs text-stone-200 placeholder:text-stone-500 focus:outline-none focus:border-amber-500 resize-none h-20"
                />

                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => setReviewModalOpen(false)}
                    className="flex-1 py-2.5 bg-stone-800 hover:bg-stone-750 text-stone-300 font-semibold rounded-xl text-xs"
                  >
                    Close
                  </button>
                  <button
                    type="submit"
                    className="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 text-stone-950 font-black rounded-xl text-xs shadow"
                  >
                    Submit Review
                  </button>
                </div>
              </form>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}
