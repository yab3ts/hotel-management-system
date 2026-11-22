import React, { useEffect, useState, useRef } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { 
  BellRing, 
  CreditCard, 
  CheckCircle2, 
  Clock, 
  Users, 
  UtensilsCrossed, 
  Sparkles, 
  RefreshCw, 
  AlertCircle,
  ChefHat,
  Receipt,
  X,
  Volume2,
  VolumeX,
  Sparkle,
  WifiOff
} from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";
import { Link } from "react-router-dom";
import { playNotificationSound, unlockAudio, isAudioUnlocked } from "@/lib/sound";

interface TableFloorItem {
  id: number;
  table_number: string;
  name: string;
  capacity: number;
  zone: string;
  status: "available" | "occupied" | "billing" | "cleaning";
  qr_token: string;
  active_session?: {
    id: number;
    status: string;
    total: string;
    opened_at: string;
    orders_count: number;
  } | null;
}

interface ServiceCallAlert {
  id: number;
  type: string;
  status: string;
  created_at: string;
  table: {
    id: number;
    table_number: string;
    name: string;
    zone: string;
    status: string;
  };
}

interface ReadyOrderAlert {
  id: number;
  order_number: string;
  guest_name: string;
  table: {
    id: number;
    table_number: string;
  };
  items: Array<{
    id: number;
    item_name: string;
    quantity: number;
    status: string;
  }>;
}

export default function WaiterDashboard() {
  const [tables, setTables] = useState<TableFloorItem[]>([]);
  const [serviceCalls, setServiceCalls] = useState<ServiceCallAlert[]>([]);
  const [readyOrders, setReadyOrders] = useState<ReadyOrderAlert[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [selectedTable, setSelectedTable] = useState<TableFloorItem | null>(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [soundEnabled, setSoundEnabled] = useState(true);
  const [managerOverride, setManagerOverride] = useState(false);
  const [settleNotes, setSettleNotes] = useState("");
  const [isOnline, setIsOnline] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<Date>(new Date());
  const [audioUnlocked, setAudioUnlocked] = useState(() => isAudioUnlocked());

  const prevServiceCallsCount = useRef(0);
  const prevReadyOrdersCount = useRef(0);

  const fetchFloorData = async (isManual = false) => {
    try {
      if (isManual) setRefreshing(true);
      const [tablesRes, alertsRes] = await Promise.all([
        api.getWaiterTables(),
        api.getWaiterAlerts(),
      ]);
      setTables(Array.isArray(tablesRes) ? tablesRes : []);
      setIsOnline(true);
      setLastUpdated(new Date());

      const incomingCalls: ServiceCallAlert[] = alertsRes?.service_calls || [];
      const incomingReady: ReadyOrderAlert[] = alertsRes?.ready_orders || [];

      // Sound triggers for waiter
      if (soundEnabled) {
        if (incomingCalls.length > prevServiceCallsCount.current && prevServiceCallsCount.current !== 0) {
          playNotificationSound("waiter_call");
        } else if (incomingReady.length > prevReadyOrdersCount.current && prevReadyOrdersCount.current !== 0) {
          playNotificationSound("order_ready");
        }
      }

      prevServiceCallsCount.current = incomingCalls.length;
      prevReadyOrdersCount.current = incomingReady.length;

      setServiceCalls(incomingCalls);
      setReadyOrders(incomingReady);
    } catch (err: any) {
      console.error("Failed to load waiter floor:", err);
      setIsOnline(false);
    } finally {
      setLoading(false);
      if (isManual) setRefreshing(false);
    }
  };

  const handleEnableAudio = async () => {
    const success = await unlockAudio();
    if (success) {
      setAudioUnlocked(true);
      toast({
        title: "Audio Alerts Active",
        description: "Chimes enabled for incoming table calls and ready dishes.",
      });
    }
  };

  useEffect(() => {
    fetchFloorData();

    // Staff screen: poll continuously every 5s regardless of tab visibility so alerts fire even when screen locked
    const interval = setInterval(() => {
      fetchFloorData();
    }, 5000);

    return () => clearInterval(interval);
  }, [soundEnabled]);

  const handleResolveAlert = async (alertId: number) => {
    try {
      await api.resolveServiceRequest(alertId);
      toast({
        title: "Service Call Attended",
        description: "Request marked resolved.",
      });
      fetchFloorData();
    } catch (err: any) {
      toast({
        title: "Failed",
        description: err.message || "Could not resolve request.",
        variant: "destructive",
      });
    }
  };

  const handleServeOrder = async (orderId: number) => {
    try {
      await api.serveOrder(orderId);
      toast({
        title: "Order Served",
        description: "Dishes marked served to guest.",
      });
      fetchFloorData();
    } catch (err: any) {
      toast({
        title: "Failed",
        description: err.message || "Could not serve order.",
        variant: "destructive",
      });
    }
  };

  const handleSettleSession = async (table: TableFloorItem) => {
    if (!table.active_session) return;
    try {
      setActionLoading(true);
      await api.settleSession(table.active_session.id, {
        payment_method: "card",
        paid_amount: parseFloat(table.active_session.total || "0"),
        manager_override: managerOverride,
        notes: settleNotes || undefined,
      });
      toast({
        title: "Table Settle Complete",
        description: `${table.table_number} tab closed. Marked as Cleaning.`,
      });
      setSelectedTable(null);
      setManagerOverride(false);
      setSettleNotes("");
      fetchFloorData(true);
    } catch (err: any) {
      toast({
        title: "Settlement Failed",
        description: err.message || "Could not settle session.",
        variant: "destructive",
      });
    } finally {
      setActionLoading(false);
    }
  };

  const handleMarkClean = async (table: TableFloorItem) => {
    try {
      setActionLoading(true);
      await api.markTableClean(table.id);
      toast({
        title: "Table Ready",
        description: `${table.table_number} is now Available for seating.`,
      });
      setSelectedTable(null);
      fetchFloorData(true);
    } catch (err: any) {
      toast({
        title: "Failed",
        description: err.message || "Could not mark clean.",
        variant: "destructive",
      });
    } finally {
      setActionLoading(false);
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case "occupied":
        return "bg-blue-500/10 border-blue-500/30 text-blue-400";
      case "billing":
        return "bg-amber-500/15 border-amber-500/40 text-amber-300";
      case "cleaning":
        return "bg-purple-500/15 border-purple-500/30 text-purple-400";
      default:
        return "bg-emerald-500/10 border-emerald-500/20 text-emerald-400";
    }
  };

  return (
    <div className="min-h-screen bg-stone-950 text-stone-100 flex flex-col">
      {/* Top Header */}
      <header className="border-b border-stone-800 bg-stone-900/70 backdrop-blur-md sticky top-0 z-20 px-6 py-4 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center text-amber-400 font-black text-xl">
            W
          </div>
          <div>
            <h1 className="font-black text-lg text-stone-100 flex items-center gap-2">
              Waiter Floor Companion
              <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
            </h1>
            <p className="text-xs text-stone-400">Live Table Sessions & Service Alerts</p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <span className="text-[10px] text-stone-500 hidden md:inline">
            Updated {Math.floor((Date.now() - lastUpdated.getTime()) / 1000)}s ago
          </span>
          {!audioUnlocked && (
            <button
              onClick={handleEnableAudio}
              className="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-stone-950 text-xs font-bold flex items-center gap-1.5 shadow animate-pulse"
            >
              <Volume2 className="w-3.5 h-3.5" />
              Enable Audio Alerts
            </button>
          )}
          <button
            onClick={() => setSoundEnabled((prev) => !prev)}
            className={`p-2 rounded-xl text-xs font-semibold flex items-center gap-1.5 border transition-colors ${
              soundEnabled
                ? "bg-amber-500/10 text-amber-300 border-amber-500/30 hover:bg-amber-500/20"
                : "bg-stone-800 text-stone-500 border-stone-700 hover:text-stone-300"
            }`}
            title={soundEnabled ? "Mute alert chimes" : "Unmute alert chimes"}
          >
            {soundEnabled ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
            <span className="hidden sm:inline">{soundEnabled ? "Sound On" : "Muted"}</span>
          </button>
          <button
            onClick={() => fetchFloorData(true)}
            disabled={refreshing}
            className="px-3.5 py-1.5 rounded-xl bg-stone-800 hover:bg-stone-750 text-xs font-semibold text-stone-300 flex items-center gap-2 border border-stone-700 transition-colors"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? "animate-spin text-amber-400" : ""}`} />
            Sync Floor
          </button>
          <Link
            to="/admin"
            className="px-3.5 py-1.5 rounded-xl bg-stone-850 hover:bg-stone-800 text-xs font-semibold text-stone-300 border border-stone-700 transition-colors"
          >
            Admin
          </Link>
        </div>
      </header>

      {/* Offline / Connection Lost Banner */}
      {!isOnline && (
        <div className="bg-red-500/20 border-b border-red-500/40 text-red-300 px-6 py-2.5 flex items-center justify-between text-xs font-bold">
          <div className="flex items-center gap-2">
            <WifiOff className="w-4 h-4 animate-bounce" />
            <span>Connection lost to server. Attempting automatic reconnection...</span>
          </div>
          <button
            onClick={() => fetchFloorData(true)}
            className="px-2.5 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg text-[11px]"
          >
            Retry Now
          </button>
        </div>
      )}

      {/* Main Container */}
      <main className="flex-1 max-w-7xl w-full mx-auto p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Column: Alerts & Kitchen Ready (1 col on lg) */}
        <div className="lg:col-span-1 space-y-6">
          {/* Live Service Calls */}
          <div className="bg-stone-900 border border-stone-800 rounded-3xl p-5 shadow-lg">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-bold text-sm text-stone-200 flex items-center gap-2">
                <BellRing className="w-4 h-4 text-amber-400 animate-bounce" />
                Active Table Calls
              </h2>
              <span className="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-400">
                {serviceCalls.length}
              </span>
            </div>

            {serviceCalls.length === 0 ? (
              <p className="text-xs text-stone-500 text-center py-6">
                All guest tables currently satisfied. No pending calls!
              </p>
            ) : (
              <div className="space-y-2.5">
                {serviceCalls.map((call) => (
                  <div
                    key={call.id}
                    className="bg-stone-950 border border-amber-500/30 rounded-2xl p-3.5 flex items-center justify-between"
                  >
                    <div>
                      <span className="font-bold text-amber-300 text-xs block">
                        {call.table.table_number} ({call.table.zone})
                      </span>
                      <span className="text-[11px] text-stone-300 capitalize">
                        {call.type === "request_bill" ? "💳 Requesting Bill" : "🛎️ Calling Waiter"}
                      </span>
                    </div>
                    <button
                      onClick={() => handleResolveAlert(call.id)}
                      className="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-stone-950 font-bold text-xs rounded-xl transition-colors"
                    >
                      Attend
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Kitchen Ready to Serve Orders */}
          <div className="bg-stone-900 border border-stone-800 rounded-3xl p-5 shadow-lg">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-bold text-sm text-stone-200 flex items-center gap-2">
                <ChefHat className="w-4 h-4 text-emerald-400" />
                Ready to Serve from Kitchen
              </h2>
              <span className="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-400">
                {readyOrders.length}
              </span>
            </div>

            {readyOrders.length === 0 ? (
              <p className="text-xs text-stone-500 text-center py-6">
                No orders waiting for pickup right now.
              </p>
            ) : (
              <div className="space-y-2.5">
                {readyOrders.map((ord) => (
                  <div
                    key={ord.id}
                    className="bg-stone-950 border border-emerald-500/30 rounded-2xl p-3.5 flex items-center justify-between"
                  >
                    <div>
                      <span className="font-bold text-emerald-300 text-xs block">
                        {ord.table.table_number} • {ord.guest_name}
                      </span>
                      <span className="text-[11px] text-stone-400">
                        {ord.items.map((i) => `${i.quantity}x ${i.item_name}`).join(", ")}
                      </span>
                    </div>
                    <button
                      onClick={() => handleServeOrder(ord.id)}
                      className="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-stone-950 font-bold text-xs rounded-xl transition-colors"
                    >
                      Serve
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Right Columns: Floor Plan Grid (2 cols on lg) */}
        <div className="lg:col-span-2 space-y-6">
          <div className="bg-stone-900 border border-stone-800 rounded-3xl p-6 shadow-xl">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
              <div>
                <h2 className="text-base font-bold text-stone-100">Restaurant Floor Overview</h2>
                <p className="text-xs text-stone-400">Click any table to view live running tab & close session</p>
              </div>

              {/* Status Legend */}
              <div className="flex items-center gap-2 text-[11px] flex-wrap">
                <span className="px-2 py-0.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
                  Available
                </span>
                <span className="px-2 py-0.5 rounded-lg bg-blue-500/10 border border-blue-500/30 text-blue-400">
                  Occupied
                </span>
                <span className="px-2 py-0.5 rounded-lg bg-amber-500/15 border border-amber-500/40 text-amber-300">
                  Needs Bill
                </span>
                <span className="px-2 py-0.5 rounded-lg bg-purple-500/15 border border-purple-500/30 text-purple-400">
                  Cleaning
                </span>
              </div>
            </div>

            {/* Tables Grid */}
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
              {tables.map((table) => {
                const colorClass = getStatusColor(table.status);
                return (
                  <motion.div
                    key={table.id}
                    whileHover={{ scale: 1.02 }}
                    whileTap={{ scale: 0.98 }}
                    onClick={() => setSelectedTable(table)}
                    className={`cursor-pointer border rounded-2xl p-4 flex flex-col justify-between min-h-[140px] transition-all ${colorClass}`}
                  >
                    <div>
                      <div className="flex items-center justify-between mb-1">
                        <span className="font-black text-sm text-stone-100">
                          {table.table_number}
                        </span>
                        <span className="text-[10px] uppercase font-bold opacity-80">
                          {table.zone}
                        </span>
                      </div>
                      <p className="text-[11px] text-stone-300">{table.name || "Dining Table"}</p>
                      <p className="text-[10px] text-stone-400 flex items-center gap-1 mt-1">
                        <Users className="w-3 h-3" /> Seats {table.capacity}
                      </p>
                    </div>

                    <div className="border-t border-stone-800/60 pt-2 mt-2">
                      {table.active_session ? (
                        <div className="flex justify-between items-center text-xs font-bold text-stone-100">
                          <span>Tab:</span>
                          <span className="text-amber-400">€{table.active_session.total}</span>
                        </div>
                      ) : (
                        <span className="text-[11px] font-semibold capitalize opacity-90">
                          {table.status}
                        </span>
                      )}
                    </div>
                  </motion.div>
                );
              })}
            </div>
          </div>
        </div>
      </main>

      {/* Table Session Details Modal */}
      {selectedTable && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <motion.div
            initial={{ scale: 0.95, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            className="bg-stone-900 border border-stone-800 rounded-3xl max-w-md w-full p-6 shadow-2xl relative"
          >
            <button
              onClick={() => setSelectedTable(null)}
              className="absolute top-4 right-4 p-2 text-stone-400 hover:text-stone-200"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-4">
              <div className="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 font-black text-lg flex items-center justify-center">
                {selectedTable.table_number.replace(/\D/g, "") || "T"}
              </div>
              <div>
                <h3 className="text-lg font-bold text-stone-100">{selectedTable.table_number}</h3>
                <p className="text-xs text-stone-400">
                  {selectedTable.zone} Zone • Capacity: {selectedTable.capacity} guests
                </p>
              </div>
            </div>

            <div className="bg-stone-950 border border-stone-800 rounded-2xl p-4 mb-5 space-y-2 text-xs">
              <div className="flex justify-between">
                <span className="text-stone-400">Status</span>
                <span className="font-bold capitalize text-stone-200">{selectedTable.status}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-stone-400">QR Code Link</span>
                <Link
                  to={`/t/${selectedTable.qr_token}`}
                  target="_blank"
                  className="font-bold text-amber-400 hover:underline"
                >
                  /t/{selectedTable.qr_token}
                </Link>
              </div>
              {selectedTable.active_session && (
                <>
                  <div className="flex justify-between border-t border-stone-800 pt-2 font-bold">
                    <span className="text-stone-300">Active Tab Total</span>
                    <span className="text-amber-400 text-sm">€{selectedTable.active_session.total}</span>
                  </div>
                  <div className="flex justify-between text-stone-400 text-[11px]">
                    <span>Orders placed</span>
                    <span>{selectedTable.active_session.orders_count} orders</span>
                  </div>
                </>
              )}
            </div>

            {/* Modal Actions */}
            <div className="space-y-2">
              {selectedTable.active_session && (
                <>
                  <label className="flex items-center gap-2 text-[11px] text-stone-400 cursor-pointer pb-1">
                    <input
                      type="checkbox"
                      checked={managerOverride}
                      onChange={(e) => setManagerOverride(e.target.checked)}
                      className="rounded border-stone-750 bg-stone-900 text-amber-500 focus:ring-0"
                    />
                    <span>Manager override (allow settle if discrepancy/comped)</span>
                  </label>
                  {managerOverride && (
                    <input
                      type="text"
                      placeholder="Reason for manager override..."
                      value={settleNotes}
                      onChange={(e) => setSettleNotes(e.target.value)}
                      className="w-full bg-stone-950 border border-stone-800 rounded-xl px-3 py-1.5 text-xs text-stone-200 placeholder-stone-600 mb-2 focus:outline-none focus:border-amber-500"
                    />
                  )}
                  <button
                    disabled={actionLoading}
                    onClick={() => handleSettleSession(selectedTable)}
                    className="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs shadow-md transition-colors disabled:opacity-50"
                  >
                    {actionLoading ? "Processing..." : "Settle Bill & Close Session (Move to Cleaning)"}
                  </button>
                </>
              )}

              {selectedTable.status === "cleaning" && (
                <button
                  disabled={actionLoading}
                  onClick={() => handleMarkClean(selectedTable)}
                  className="w-full py-3 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-xl text-xs shadow-md transition-colors disabled:opacity-50"
                >
                  {actionLoading ? "Processing..." : "Mark Table Clean & Free (Set Available)"}
                </button>
              )}

              <button
                type="button"
                onClick={() => setSelectedTable(null)}
                className="w-full py-2.5 bg-stone-800 hover:bg-stone-750 text-stone-300 font-semibold rounded-xl text-xs transition-colors"
              >
                Close
              </button>
            </div>
          </motion.div>
        </div>
      )}
    </div>
  );
}
