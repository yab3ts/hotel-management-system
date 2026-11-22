import { useState, useEffect, useCallback, useRef } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { 
  Clock, 
  Check, 
  Flame, 
  AlertTriangle, 
  Volume2, 
  VolumeX, 
  Maximize, 
  ChefHat, 
  RefreshCw, 
  ArrowLeft 
} from "lucide-react";
import { Link } from "react-router-dom";
import { api } from "@/lib/api";
import { playNotificationSound } from "@/lib/sound";

interface DisplayItem {
  id: number;
  item_name: string;
  quantity: number;
  status: string;
  special_notes?: string;
}

interface DisplayOrder {
  id: number;
  order_number: string;
  table_number: string;
  guest_name: string;
  items: DisplayItem[];
  status: "placed" | "accepted" | "preparing" | "ready";
  placedAt: number;
  notes?: string;
}

function KdsTimer({ placedAt }: { placedAt: number }) {
  const [elapsed, setElapsed] = useState(0);

  useEffect(() => {
    const update = () => setElapsed(Math.max(0, Math.floor((Date.now() - placedAt) / 1000)));
    update();
    const id = setInterval(update, 1000);
    return () => clearInterval(id);
  }, [placedAt]);

  const mins = Math.floor(elapsed / 60);
  const secs = elapsed % 60;

  // Age color: green (< 8 mins) -> yellow (8 - 15 mins) -> red (> 15 mins)
  const colorClass = mins >= 15 
    ? "text-red-400 animate-pulse font-black" 
    : mins >= 8 
    ? "text-amber-400 font-bold" 
    : "text-emerald-400 font-bold";

  return (
    <span className={`font-mono text-sm flex items-center gap-1 ${colorClass}`}>
      <Clock className="w-3.5 h-3.5" />
      {String(mins).padStart(2, "0")}:{String(secs).padStart(2, "0")}
      {mins >= 15 && <AlertTriangle className="w-3.5 h-3.5 ml-0.5" />}
    </span>
  );
}

export default function KitchenDisplay() {
  const [orders, setOrders] = useState<DisplayOrder[]>([]);
  const [soundOn, setSoundOn] = useState(true);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const prevCountRef = useRef(0);

  const fetchKdsOrders = async (isManual = false) => {
    try {
      if (isManual) setRefreshing(true);
      const res = await api.getKitchenOrders();
      if (Array.isArray(res)) {
        // Filter orders that need kitchen action (placed, accepted, preparing)
        const active = res
          .filter((o: any) => ["placed", "accepted", "preparing"].includes(o.status))
          .map((o: any) => ({
            id: o.id,
            order_number: o.order_number,
            table_number: o.table?.table_number || "Takeaway",
            guest_name: o.guest_name || "Guest",
            items: (o.items || []).map((i: any) => ({
              id: i.id,
              item_name: i.item_name,
              quantity: i.quantity,
              status: i.status || "placed",
              special_notes: i.special_notes,
            })),
            status: o.status,
            placedAt: o.placed_at ? new Date(o.placed_at).getTime() : new Date(o.created_at).getTime(),
            notes: o.notes,
          }));

        if (soundOn && active.length > prevCountRef.current && prevCountRef.current !== 0) {
          playNotificationSound("kitchen_new_order");
        }
        prevCountRef.current = active.length;

        setOrders(active);
      }
    } catch (err: any) {
      console.warn("KDS sync error:", err);
    } finally {
      if (isManual) setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchKdsOrders();

    const interval = setInterval(() => {
      if (!document.hidden) {
        fetchKdsOrders();
      }
    }, 4000);

    return () => clearInterval(interval);
  }, [soundOn]);

  const toggleFullscreen = useCallback(() => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen();
      setIsFullscreen(true);
    } else {
      document.exitFullscreen();
      setIsFullscreen(false);
    }
  }, []);

  const bumpOrder = async (orderId: number) => {
    try {
      await api.updateOrderStatus(orderId, "ready");
      if (soundOn) {
        playNotificationSound("order_ready");
      }
      setOrders((prev) => prev.filter((o) => o.id !== orderId));
    } catch (err: any) {
      console.error("Bump failed:", err);
    }
  };

  const startPrep = async (orderId: number) => {
    try {
      await api.updateOrderStatus(orderId, "preparing");
      fetchKdsOrders();
    } catch (err: any) {
      console.error("Start prep failed:", err);
    }
  };

  return (
    <div className="min-h-screen bg-stone-950 text-stone-100 flex flex-col p-4">
      {/* Top Bar */}
      <header className="flex items-center justify-between border-b border-stone-800 pb-3 mb-4">
        <div className="flex items-center gap-3">
          <Link
            to="/kitchen/orders"
            className="p-2 rounded-xl bg-stone-900 border border-stone-800 text-stone-300 hover:text-white"
            title="Back to Orders"
          >
            <ArrowLeft className="w-5 h-5" />
          </Link>
          <div>
            <h1 className="font-black text-xl text-stone-100 flex items-center gap-2">
              Kitchen Display Screen (KDS)
              <span className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse" />
            </h1>
            <p className="text-xs text-stone-400">Live order tickets grouped by table</p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <span className="px-3 py-1 bg-amber-500/15 border border-amber-500/30 text-amber-300 font-bold text-xs rounded-xl">
            {orders.length} Active Tickets
          </span>

          <button
            onClick={() => setSoundOn(!soundOn)}
            className={`p-2 rounded-xl border text-xs font-semibold ${
              soundOn ? "bg-amber-500/20 text-amber-400 border-amber-500/30" : "bg-stone-900 text-stone-400 border-stone-800"
            }`}
          >
            {soundOn ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
          </button>

          <button
            onClick={toggleFullscreen}
            className="p-2 rounded-xl bg-stone-900 border border-stone-800 text-stone-300 hover:text-white"
            title="Toggle Fullscreen"
          >
            <Maximize className="w-4 h-4" />
          </button>
        </div>
      </header>

      {/* Tickets Grid */}
      <main className="flex-1 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <AnimatePresence>
          {orders.length === 0 ? (
            <div className="col-span-full flex flex-col items-center justify-center py-24 text-stone-500">
              <ChefHat className="w-16 h-16 mb-3 opacity-30 text-amber-500" />
              <h2 className="text-lg font-bold text-stone-400">Kitchen Display is Clear</h2>
              <p className="text-xs text-stone-500">All customer orders have been prepared and bumped.</p>
            </div>
          ) : (
            orders.map((order) => {
              const mins = Math.floor((Date.now() - order.placedAt) / 60000);
              const cardBorder = mins >= 15 
                ? "border-red-500/60 bg-red-950/20" 
                : mins >= 8 
                ? "border-amber-500/50 bg-stone-900" 
                : "border-stone-800 bg-stone-900";

              return (
                <motion.div
                  key={order.id}
                  layout
                  initial={{ opacity: 0, scale: 0.95 }}
                  animate={{ opacity: 1, scale: 1 }}
                  exit={{ opacity: 0, scale: 0.9 }}
                  className={`border-2 rounded-3xl p-4 flex flex-col justify-between shadow-xl ${cardBorder}`}
                >
                  <div>
                    {/* Header: Table & Timer */}
                    <div className="flex items-center justify-between border-b border-stone-800 pb-2 mb-3">
                      <div>
                        <span className="font-black text-lg text-stone-100 block">
                          {order.table_number}
                        </span>
                        <span className="text-xs text-stone-400">
                          {order.guest_name} • {order.order_number}
                        </span>
                      </div>
                      <KdsTimer placedAt={order.placedAt} />
                    </div>

                    {/* Table Notes / Allergies */}
                    {order.notes && (
                      <div className={`rounded-xl px-2.5 py-1 mb-2.5 text-xs font-semibold ${
                        /allerg|celiac|nut|peanut|gluten|dairy/i.test(order.notes)
                          ? "bg-red-500/20 border border-red-500/50 text-red-300 animate-pulse"
                          : "bg-amber-500/15 border border-amber-500/30 text-amber-300"
                      }`}>
                        {/allerg|celiac|nut|peanut|gluten|dairy/i.test(order.notes) ? "🚨 ALLERGY: " : "Note: "}
                        {order.notes}
                      </div>
                    )}

                    {/* Items */}
                    <div className="space-y-2 mb-4">
                      {order.items.map((it) => (
                        <div key={it.id} className="text-xs">
                          <div className="flex items-center justify-between">
                            <span className="font-black text-stone-100 text-sm">
                              {it.quantity}x {it.item_name}
                            </span>
                            {it.status === "ready" && (
                              <span className="text-emerald-400 text-[10px] font-bold">READY</span>
                            )}
                          </div>
                          {it.special_notes && (
                            <div className={`text-[11px] mt-0.5 px-2 py-0.5 rounded-md inline-block ${
                              /allerg|celiac|nut|peanut|gluten|dairy/i.test(it.special_notes)
                                ? "bg-red-500/25 border border-red-500/40 text-red-300 font-bold"
                                : "text-amber-400 italic font-medium"
                            }`}>
                              {/allerg|celiac|nut|peanut|gluten|dairy/i.test(it.special_notes) ? "⚠️ ALLERGY: " : '"'}
                              {it.special_notes}
                              {!/allerg|celiac|nut|peanut|gluten|dairy/i.test(it.special_notes) && '"'}
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  </div>

                  {/* Actions / Bump Bar */}
                  <div className="space-y-2 border-t border-stone-800/80 pt-3">
                    {order.status === "placed" && (
                      <button
                        onClick={() => startPrep(order.id)}
                        className="w-full py-2 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-xl text-xs transition-colors flex items-center justify-center gap-1.5"
                      >
                        <Flame className="w-3.5 h-3.5" /> Start Preparing
                      </button>
                    )}

                    <button
                      onClick={() => bumpOrder(order.id)}
                      className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-black rounded-xl text-xs shadow-lg transition-all flex items-center justify-center gap-1.5 transform active:scale-95"
                    >
                      <Check className="w-4 h-4" /> BUMP / MARK READY
                    </button>
                  </div>
                </motion.div>
              );
            })
          )}
        </AnimatePresence>
      </main>
    </div>
  );
}
