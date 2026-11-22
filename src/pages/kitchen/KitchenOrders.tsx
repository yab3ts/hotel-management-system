import { useState, useEffect, useRef } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { 
  Clock, 
  UtensilsCrossed, 
  Check, 
  ChefHat, 
  CheckCircle2, 
  Filter, 
  AlertTriangle, 
  RefreshCw, 
  Volume2, 
  VolumeX, 
  Wifi, 
  WifiOff 
} from "lucide-react";
import { KitchenLayout } from "@/components/KitchenLayout";
import { PageHeader } from "@/components/PageHeader";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";
import { playNotificationSound, unlockAudio, isAudioUnlocked } from "@/lib/sound";

interface KitchenItem {
  id: number;
  item_name: string;
  quantity: number;
  status: string;
  special_notes?: string;
  customizations?: string[];
}

interface KitchenOrder {
  id: number;
  order_number: string;
  table_number: string;
  guest_name: string;
  items: KitchenItem[];
  status: "placed" | "accepted" | "preparing" | "ready" | "served" | "cancelled";
  placedAt: number;
  estimatedMin: number;
  notes?: string;
}

const statusOrder: KitchenOrder["status"][] = ["placed", "accepted", "preparing", "ready", "served"];

const statusLabels: Record<string, string> = {
  placed: "New Order",
  accepted: "Accepted",
  preparing: "Preparing",
  ready: "Ready for Pickup",
  served: "Served",
  cancelled: "Cancelled",
};

const statusColors: Record<string, string> = {
  placed: "bg-amber-500/15 text-amber-400 border border-amber-500/30",
  accepted: "bg-blue-500/15 text-blue-400 border border-blue-500/30",
  preparing: "bg-orange-500/15 text-orange-400 border border-orange-500/30",
  ready: "bg-emerald-500/15 text-emerald-400 border border-emerald-500/30",
  served: "bg-stone-800 text-stone-400 border border-stone-700",
  cancelled: "bg-red-500/15 text-red-400 border border-red-500/30",
};

function ElapsedTimer({ placedAt, estimatedMin }: { placedAt: number; estimatedMin: number }) {
  const [elapsedSec, setElapsedSec] = useState(0);

  useEffect(() => {
    const update = () => setElapsedSec(Math.max(0, Math.floor((Date.now() - placedAt) / 1000)));
    update();
    const interval = setInterval(update, 1000);
    return () => clearInterval(interval);
  }, [placedAt]);

  const mins = Math.floor(elapsedSec / 60);
  const secs = elapsedSec % 60;
  const isOverdue = mins >= estimatedMin;
  const isWarning = mins >= estimatedMin * 0.75 && !isOverdue;

  return (
    <span
      className={`text-xs font-mono font-bold flex items-center gap-1 px-2.5 py-0.5 rounded-lg ${
        isOverdue
          ? "bg-red-500/20 text-red-400 animate-pulse border border-red-500/40"
          : isWarning
          ? "bg-amber-500/20 text-amber-300 border border-amber-500/30"
          : "bg-stone-800 text-stone-300"
      }`}
    >
      <Clock className="w-3 h-3" />
      {String(mins).padStart(2, "0")}:{String(secs).padStart(2, "0")}
      {isOverdue && <AlertTriangle className="w-3 h-3 text-red-400 ml-0.5" />}
    </span>
  );
}

export default function KitchenOrders() {
  const [orders, setOrders] = useState<KitchenOrder[]>([]);
  const [filterStatus, setFilterStatus] = useState<string>("All");
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [soundEnabled, setSoundEnabled] = useState(true);
  const [isOnline, setIsOnline] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<Date>(new Date());
  const [audioUnlocked, setAudioUnlocked] = useState(() => isAudioUnlocked());
  const prevOrderCountRef = useRef(0);

  const fetchOrders = async (isManual = false) => {
    try {
      if (isManual) setRefreshing(true);
      const res = await api.getKitchenOrders();
      setIsOnline(true);
      setLastUpdated(new Date());

      if (Array.isArray(res)) {
        const mapped: KitchenOrder[] = res.map((o: any) => ({
          id: o.id,
          order_number: o.order_number,
          table_number: o.table?.table_number || "Takeaway",
          guest_name: o.guest_name || "Guest",
          status: o.status || "placed",
          notes: o.notes,
          placedAt: o.placed_at ? new Date(o.placed_at).getTime() : new Date(o.created_at).getTime(),
          estimatedMin: 15,
          items: (o.items || []).map((it: any) => ({
            id: it.id,
            item_name: it.item_name,
            quantity: it.quantity,
            status: it.status || "placed",
            special_notes: it.special_notes,
            customizations: it.customizations,
          })),
        }));

        // Sound alert if new orders arrived
        const placedCount = mapped.filter((o) => o.status === "placed").length;
        if (soundEnabled && placedCount > prevOrderCountRef.current && prevOrderCountRef.current !== 0) {
          playNotificationSound("kitchen_new_order");
        }
        prevOrderCountRef.current = placedCount;

        setOrders(mapped);
      }
    } catch (err: any) {
      console.warn("Kitchen orders fetch failed:", err);
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
        title: "Kitchen Audio Alerts Active",
        description: "Sound alerts unlocked for incoming kitchen orders.",
      });
    }
  };

  useEffect(() => {
    fetchOrders();

    // Staff screen: continuous polling so audio chimes trigger even when screen is locked
    const interval = setInterval(() => {
      fetchOrders();
    }, 5000);

    return () => clearInterval(interval);
  }, [soundEnabled]);

  const advanceOrderStatus = async (orderId: number, currentStatus: KitchenOrder["status"]) => {
    const currentIndex = statusOrder.indexOf(currentStatus);
    if (currentIndex < 0 || currentIndex >= statusOrder.length - 1) return;
    const nextStatus = statusOrder[currentIndex + 1];

    try {
      await api.updateOrderStatus(orderId, nextStatus);
      if (nextStatus === "ready" && soundEnabled) {
        playNotificationSound("order_ready");
      }
      toast({
        title: `Order Updated: ${statusLabels[nextStatus]}`,
        description: `Order #${orderId} moved to ${statusLabels[nextStatus]}.`,
      });
      fetchOrders();
    } catch (err: any) {
      toast({
        title: "Update Failed",
        description: err.message || "Could not advance order.",
        variant: "destructive",
      });
    }
  };

  const markItemComplete = async (itemId: number) => {
    try {
      await api.completeKitchenItem(itemId);
      fetchOrders();
    } catch (err: any) {
      console.error("Item complete failed:", err);
    }
  };

  const filtered = filterStatus === "All" 
    ? orders 
    : orders.filter((o) => o.status === filterStatus);

  return (
    <KitchenLayout>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <PageHeader 
          title="Kitchen Order Queue" 
          subtitle="Real-time preparation pipeline with live guest tickets & notes" 
        />

        <div className="flex items-center gap-2">
          {/* Connection Indicator */}
          <div className={`px-2.5 py-1 rounded-xl text-xs font-semibold flex items-center gap-1.5 border ${
            isOnline ? "bg-emerald-500/10 text-emerald-400 border-emerald-500/20" : "bg-red-500/10 text-red-400 border-red-500/20 animate-pulse"
          }`}>
            {isOnline ? <Wifi className="w-3.5 h-3.5" /> : <WifiOff className="w-3.5 h-3.5" />}
            {isOnline ? "Live Sync" : "Reconnecting..."}
          </div>

          {!audioUnlocked && (
            <button
              onClick={handleEnableAudio}
              className="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-stone-950 text-xs font-bold flex items-center gap-1.5 shadow animate-pulse"
            >
              <Volume2 className="w-3.5 h-3.5" />
              Enable Audio Alerts
            </button>
          )}

          {/* Sound Toggle */}
          <button
            onClick={() => setSoundEnabled(!soundEnabled)}
            className={`p-2 rounded-xl border text-xs font-semibold transition-colors ${
              soundEnabled ? "bg-amber-500/15 text-amber-400 border-amber-500/30" : "bg-card text-muted-foreground border-border"
            }`}
            title={soundEnabled ? "Sound Alerts Enabled" : "Sound Alerts Muted"}
          >
            {soundEnabled ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
          </button>

          {/* Sync Button */}
          <button
            onClick={() => fetchOrders(true)}
            disabled={refreshing}
            className="px-3.5 py-2 rounded-xl bg-card border border-border text-xs font-semibold text-foreground flex items-center gap-1.5 hover:bg-muted transition-colors"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? "animate-spin text-primary" : ""}`} />
            Sync
          </button>
        </div>
      </div>

      {/* Filter Tabs */}
      <div className="flex flex-wrap items-center gap-2 mb-6">
        <Filter className="w-4 h-4 text-muted-foreground flex-shrink-0" />
        {["All", ...statusOrder].map((s) => (
          <button
            key={s}
            onClick={() => setFilterStatus(s)}
            className={`text-xs font-semibold px-3 py-1.5 rounded-xl transition-all capitalize ${
              filterStatus === s 
                ? "bg-primary text-primary-foreground shadow" 
                : "bg-card border border-border text-muted-foreground hover:text-foreground"
            }`}
          >
            {s === "All" ? "All Orders" : statusLabels[s]}
          </button>
        ))}
      </div>

      {/* Order Cards List */}
      <div className="space-y-3">
        <AnimatePresence>
          {filtered.length === 0 ? (
            <div className="bg-card border border-border rounded-2xl p-8 text-center text-muted-foreground">
              <UtensilsCrossed className="w-10 h-10 mx-auto mb-2 opacity-40" />
              <p className="text-sm font-semibold">No orders currently in this queue</p>
              <p className="text-xs">Orders placed via QR codes will automatically chime and appear here.</p>
            </div>
          ) : (
            filtered.map((order, i) => {
              const nextStatus = statusOrder[statusOrder.indexOf(order.status) + 1];
              return (
                <motion.div
                  key={order.id}
                  layout
                  initial={{ opacity: 0, y: 15 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -15 }}
                  transition={{ delay: i * 0.03 }}
                  className="bg-card border border-border rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow"
                >
                  <div className="flex flex-col gap-3">
                    {/* Header line */}
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 pb-2.5">
                      <div className="flex items-center gap-2">
                        <span className="font-display font-black text-base text-foreground">
                          {order.table_number}
                        </span>
                        <span className="text-xs font-semibold text-muted-foreground">
                          • {order.guest_name}
                        </span>
                        <span className="text-xs font-mono text-muted-foreground/60">
                          ({order.order_number})
                        </span>
                      </div>

                      <div className="flex items-center gap-2">
                        <span className={`text-[11px] font-bold px-2.5 py-0.5 rounded-full ${statusColors[order.status]}`}>
                          {statusLabels[order.status]}
                        </span>
                        <ElapsedTimer placedAt={order.placedAt} estimatedMin={order.estimatedMin} />
                      </div>
                    </div>

                    {/* Table / Order Notes */}
                    {order.notes && (
                      <div className={`rounded-xl px-3 py-1.5 text-xs font-semibold ${
                        /allerg|celiac|nut|peanut|gluten|dairy/i.test(order.notes)
                          ? "bg-red-500/20 border border-red-500/40 text-red-300 animate-pulse"
                          : "bg-amber-500/10 border border-amber-500/20 text-amber-300 font-medium"
                      }`}>
                        {/allerg|celiac|nut|peanut|gluten|dairy/i.test(order.notes) ? "🚨 ALLERGY ALERT: " : "⚠️ Note: "}
                        "{order.notes}"
                      </div>
                    )}

                    {/* Dish Items List */}
                    <div className="space-y-2">
                      {order.items.map((it) => (
                        <div key={it.id} className="flex items-start justify-between text-xs py-1">
                          <div className="flex-1">
                            <span className="font-bold text-foreground">
                              {it.quantity}x {it.item_name}
                            </span>
                            {it.special_notes && (
                              <div className={`text-[11px] mt-1 px-2 py-0.5 rounded-md inline-block ${
                                /allerg|celiac|nut|peanut|gluten|dairy/i.test(it.special_notes)
                                  ? "bg-red-500/20 border border-red-500/40 text-red-300 font-bold"
                                  : "bg-amber-500/10 text-amber-400 italic font-medium"
                              }`}>
                                {/allerg|celiac|nut|peanut|gluten|dairy/i.test(it.special_notes) ? "⚠️ ALLERGY: " : "Instructions: "}
                                "{it.special_notes}"
                              </div>
                            )}
                          </div>

                          <button
                            onClick={() => markItemComplete(it.id)}
                            className={`px-2 py-0.5 rounded-lg text-[10px] font-bold border transition-colors ${
                              it.status === "ready"
                                ? "bg-emerald-500/20 border-emerald-500 text-emerald-400"
                                : "bg-secondary border-border text-muted-foreground hover:border-primary"
                            }`}
                          >
                            {it.status === "ready" ? "✓ Done" : "Mark Done"}
                          </button>
                        </div>
                      ))}
                    </div>

                    {/* Bottom Action Bar */}
                    <div className="flex items-center justify-between border-t border-border/50 pt-2.5 mt-1">
                      <span className="text-[11px] text-muted-foreground">
                        {order.items.length} items on ticket
                      </span>

                      {nextStatus && (
                        <button
                          onClick={() => advanceOrderStatus(order.id, order.status)}
                          className="px-4 py-1.5 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold flex items-center gap-1.5 shadow transition-colors"
                        >
                          <ChefHat className="w-3.5 h-3.5" />
                          Move to {statusLabels[nextStatus]} →
                        </button>
                      )}
                    </div>
                  </div>
                </motion.div>
              );
            })
          )}
        </AnimatePresence>
      </div>
    </KitchenLayout>
  );
}
