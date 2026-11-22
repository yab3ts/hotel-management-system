import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import {
  Plus,
  Search,
  Filter,
  Eye,
  Clock,
  CheckCircle,
  XCircle,
  ChefHat,
  UtensilsCrossed,
  RefreshCw,
  AlertCircle,
  DollarSign,
  ChevronRight,
  ShoppingBag,
  Trash2,
} from "lucide-react";
import { AdminLayout as AppLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
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

type OrderStatus = "placed" | "accepted" | "preparing" | "ready" | "served" | "completed" | "cancelled";

interface OrderItem {
  id?: number;
  item_name: string;
  unit_price: number | string;
  quantity: number;
  subtotal?: number | string;
  notes?: string | null;
  special_notes?: string | null;
}

interface Order {
  id: number;
  order_number: string;
  order_type: string;
  status: OrderStatus;
  subtotal: number | string;
  tax: number | string;
  service_charge?: number | string;
  total: number | string;
  guest_name?: string;
  notes?: string | null;
  created_at: string;
  table?: {
    id: number;
    table_number: string;
    name?: string;
  } | null;
  items: OrderItem[];
}

interface MenuItem {
  id: number;
  name: string;
  price: string | number;
  description?: string;
  category?: { name: string };
  is_available: boolean;
}

interface TableOption {
  id: number;
  table_number: string;
  name: string;
  zone: string;
}

const statusConfig: Record<string, { label: string; color: string; border: string; icon: typeof Clock }> = {
  placed: { label: "Placed", color: "bg-amber-500/10 text-amber-600", border: "border-amber-500", icon: Clock },
  accepted: { label: "Accepted", color: "bg-blue-500/10 text-blue-600", border: "border-blue-500", icon: CheckCircle },
  preparing: { label: "Preparing", color: "bg-primary/10 text-primary", border: "border-primary", icon: ChefHat },
  ready: { label: "Ready", color: "bg-emerald-500/10 text-emerald-600", border: "border-emerald-500", icon: UtensilsCrossed },
  served: { label: "Served", color: "bg-indigo-500/10 text-indigo-600", border: "border-indigo-500", icon: CheckCircle },
  completed: { label: "Completed", color: "bg-muted text-muted-foreground", border: "border-muted", icon: CheckCircle },
  cancelled: { label: "Cancelled", color: "bg-destructive/10 text-destructive", border: "border-destructive", icon: XCircle },
};

const statusOrder: OrderStatus[] = ["placed", "accepted", "preparing", "ready", "served", "completed"];

const filterTabs = [
  { key: "All", label: "All" },
  { key: "placed", label: "Placed" },
  { key: "accepted", label: "Accepted" },
  { key: "preparing", label: "Preparing" },
  { key: "ready", label: "Ready" },
  { key: "served", label: "Served" },
  { key: "completed", label: "Completed" },
  { key: "cancelled", label: "Cancelled" },
];

export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [activeFilter, setActiveFilter] = useState<string>("All");
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);

  // POS Modal State
  const [isPosOpen, setIsPosOpen] = useState(false);
  const [menuItems, setMenuItems] = useState<MenuItem[]>([]);
  const [tables, setTables] = useState<TableOption[]>([]);
  const [posTableId, setPosTableId] = useState<string>("");
  const [posGuestName, setPosGuestName] = useState("Walk-in Guest");
  const [posNotes, setPosNotes] = useState("");
  const [posCart, setPosCart] = useState<Array<{ item: MenuItem; quantity: number; notes: string }>>([]);
  const [posSearch, setPosSearch] = useState("");
  const [isSubmittingPos, setIsSubmittingPos] = useState(false);

  const fetchOrders = async () => {
    setIsLoading(true);
    try {
      const data = await api.getKitchenOrders("All");
      const list = Array.isArray(data) ? data : [];
      setOrders(list);
      if (selectedOrder) {
        const updated = list.find((o: Order) => o.id === selectedOrder.id);
        if (updated) setSelectedOrder(updated);
      } else if (list.length > 0) {
        setSelectedOrder(list[0]);
      }
    } catch (err) {
      console.error("Failed to load orders:", err);
      toast({
        title: "Error loading orders",
        description: "Could not fetch current kitchen orders.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const loadPosData = async () => {
    try {
      const [menuRes, tablesRes] = await Promise.allSettled([
        api.getMenu(),
        api.get('/tables'),
      ]);

      if (menuRes.status === "fulfilled" && Array.isArray(menuRes.value)) {
        setMenuItems(menuRes.value);
      }
      if (tablesRes.status === "fulfilled" && Array.isArray(tablesRes.value)) {
        setTables(tablesRes.value);
        if (tablesRes.value.length > 0 && !posTableId) {
          setPosTableId(tablesRes.value[0].id.toString());
        }
      }
    } catch (err) {
      console.error("Failed to load POS data:", err);
    }
  };

  useEffect(() => {
    fetchOrders();
    const interval = setInterval(fetchOrders, 8000);
    return () => clearInterval(interval);
  }, []);

  const openPosModal = () => {
    loadPosData();
    setPosCart([]);
    setPosNotes("");
    setPosGuestName("Walk-in Guest");
    setIsPosOpen(true);
  };

  const addToPosCart = (item: MenuItem) => {
    setPosCart((prev) => {
      const existing = prev.find((i) => i.item.id === item.id);
      if (existing) {
        return prev.map((i) =>
          i.item.id === item.id ? { ...i, quantity: i.quantity + 1 } : i
        );
      }
      return [...prev, { item, quantity: 1, notes: "" }];
    });
  };

  const updateCartQty = (itemId: number, delta: number) => {
    setPosCart((prev) =>
      prev
        .map((i) => (i.item.id === itemId ? { ...i, quantity: i.quantity + delta } : i))
        .filter((i) => i.quantity > 0)
    );
  };

  const handleCreatePosOrder = async () => {
    if (posCart.length === 0) {
      toast({
        title: "Empty order",
        description: "Please add at least one item to the order.",
        variant: "destructive",
      });
      return;
    }

    setIsSubmittingPos(true);
    try {
      const payload = {
        table_id: posTableId ? Number(posTableId) : undefined,
        guest_name: posGuestName.trim() || "Walk-in Guest",
        order_type: posTableId ? "dine_in" : "takeaway",
        notes: posNotes.trim() || undefined,
        items: posCart.map((c) => ({
          menu_item_id: c.item.id,
          item_name: c.item.name,
          unit_price: Number(c.item.price),
          quantity: c.quantity,
          notes: c.notes || undefined,
        })),
      };

      const res = await api.createPosOrder(payload);
      toast({
        title: "Order placed successfully",
        description: `Order ${res.order_number || "created"} sent to kitchen!`,
      });
      setIsPosOpen(false);
      await fetchOrders();
      if (res && res.id) {
        setSelectedOrder(res);
      }
    } catch (err: any) {
      toast({
        title: "Failed to place order",
        description: err.message || "An unexpected error occurred.",
        variant: "destructive",
      });
    } finally {
      setIsSubmittingPos(false);
    }
  };

  const handleAdvanceStatus = async (order: Order) => {
    const currentIdx = statusOrder.indexOf(order.status);
    if (currentIdx === -1 || currentIdx >= statusOrder.length - 1) return;

    const nextStatus = statusOrder[currentIdx + 1];
    try {
      await api.updateOrderStatus(order.id, nextStatus);
      toast({
        title: `Order advanced to ${statusConfig[nextStatus]?.label || nextStatus}`,
        description: `Order ${order.order_number} status updated.`,
      });
      fetchOrders();
    } catch (err: any) {
      toast({
        title: "Failed to update order",
        description: err.message || "Update error.",
        variant: "destructive",
      });
    }
  };

  const handleCancelOrder = async (order: Order) => {
    const reason = window.prompt("Reason for cancellation:", "Customer requested / table error");
    if (!reason) return;

    try {
      await api.updateOrderStatus(order.id, "cancelled", reason);
      toast({
        title: "Order cancelled",
        description: `Order ${order.order_number} has been cancelled.`,
      });
      fetchOrders();
    } catch (err: any) {
      toast({
        title: "Cancellation failed",
        description: err.message || "Could not cancel order.",
        variant: "destructive",
      });
    }
  };

  const filteredOrders = orders.filter((o) => {
    const matchesFilter = activeFilter === "All" || o.status === activeFilter;
    const matchesSearch =
      o.order_number.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (o.guest_name && o.guest_name.toLowerCase().includes(searchQuery.toLowerCase())) ||
      (o.table?.table_number && o.table.table_number.toLowerCase().includes(searchQuery.toLowerCase()));
    return matchesFilter && matchesSearch;
  });

  const cartSubtotal = posCart.reduce(
    (sum, c) => sum + Number(c.item.price) * c.quantity,
    0
  );

  return (
    <AppLayout>
      <PageHeader
        title="Order Management & POS"
        subtitle="Live kitchen flow, table dispatching & walk-in order terminal"
        actions={
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={fetchOrders}
              disabled={isLoading}
              className="gap-2"
            >
              <RefreshCw className={`w-4 h-4 ${isLoading ? "animate-spin" : ""}`} />
              Refresh
            </Button>
            <Button
              onClick={openPosModal}
              className="gradient-warm text-primary-foreground font-semibold gap-2 shadow-lg hover:opacity-95"
            >
              <Plus className="w-4 h-4" /> New Walk-in / POS Order
            </Button>
          </div>
        }
      />

      {/* Filter Tabs & Search */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div className="flex gap-1.5 overflow-x-auto pb-1 max-w-full">
          {filterTabs.map((tab) => {
            const count =
              tab.key === "All"
                ? orders.length
                : orders.filter((o) => o.status === tab.key).length;
            const isActive = activeFilter === tab.key;
            return (
              <button
                key={tab.key}
                onClick={() => setActiveFilter(tab.key)}
                className={`px-3.5 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition-all ${
                  isActive
                    ? "bg-primary text-primary-foreground shadow-sm"
                    : "bg-card border border-border text-foreground hover:bg-muted"
                }`}
              >
                {tab.label} ({count})
              </button>
            );
          })}
        </div>

        <div className="relative w-full sm:w-64">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search order #, table, guest..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-9 h-9 text-xs rounded-xl"
          />
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Orders List */}
        <div className="lg:col-span-2 space-y-3">
          {filteredOrders.length === 0 ? (
            <div className="glass-card p-12 text-center rounded-2xl border border-border/50">
              <AlertCircle className="w-8 h-8 mx-auto mb-2 text-muted-foreground/40" />
              <p className="text-sm font-semibold text-foreground">No orders matching filter</p>
              <p className="text-xs text-muted-foreground mt-1">
                New QR scans and walk-in POS orders will appear automatically.
              </p>
            </div>
          ) : (
            <AnimatePresence mode="popLayout">
              {filteredOrders.map((order) => {
                const cfg = statusConfig[order.status] || statusConfig.placed;
                const SIcon = cfg.icon;
                const isSelected = selectedOrder?.id === order.id;

                return (
                  <motion.div
                    key={order.id}
                    layout
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -8 }}
                    whileHover={{ scale: 1.005 }}
                    onClick={() => setSelectedOrder(order)}
                    className={`glass-card p-4 rounded-2xl cursor-pointer transition-all border-l-4 ${
                      isSelected ? "ring-2 ring-primary/40 border-primary" : cfg.border
                    } hover:border-l-primary`}
                  >
                    <div className="flex items-center justify-between mb-2">
                      <div className="flex items-center gap-2.5">
                        <span className="font-display font-bold text-foreground">
                          {order.order_number}
                        </span>
                        {order.table?.table_number ? (
                          <span className="text-xs bg-primary/10 text-primary font-semibold px-2 py-0.5 rounded-full">
                            {order.table.table_number}
                          </span>
                        ) : (
                          <span className="text-xs bg-muted px-2 py-0.5 rounded-full text-muted-foreground">
                            Takeaway
                          </span>
                        )}
                        <span className="text-xs text-muted-foreground font-medium">
                          · {order.guest_name || "Guest"}
                        </span>
                      </div>
                      <span
                        className={`text-[11px] font-semibold px-2.5 py-0.5 rounded-full flex items-center gap-1 ${cfg.color}`}
                      >
                        <SIcon className="w-3 h-3" /> {cfg.label}
                      </span>
                    </div>

                    <p className="text-xs text-muted-foreground line-clamp-1 mb-2">
                      {order.items?.map((i) => `${i.quantity}x ${i.item_name}`).join(", ") ||
                        "No items recorded"}
                    </p>

                    <div className="flex items-center justify-between text-xs text-muted-foreground pt-1 border-t border-border/30">
                      <span>
                        {new Date(order.created_at).toLocaleTimeString([], {
                          hour: "2-digit",
                          minute: "2-digit",
                        })}
                      </span>
                      <span className="font-semibold font-mono text-foreground text-sm">
                        ${Number(order.total || 0).toFixed(2)}
                      </span>
                    </div>
                  </motion.div>
                );
              })}
            </AnimatePresence>
          )}
        </div>

        {/* Order Details & Lifecycle Actions */}
        <div>
          <AnimatePresence mode="wait">
            {selectedOrder ? (
              <motion.div
                key={selectedOrder.id}
                initial={{ opacity: 0, x: 15 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: 15 }}
                className="glass-card-elevated p-5 rounded-2xl sticky top-8 border border-border/50 shadow-sm"
              >
                <div className="flex items-center justify-between mb-2">
                  <h3 className="font-display font-bold text-xl text-foreground">
                    Order {selectedOrder.order_number}
                  </h3>
                  <span
                    className={`text-xs font-semibold px-2.5 py-0.5 rounded-full ${
                      statusConfig[selectedOrder.status]?.color || "bg-muted"
                    }`}
                  >
                    {statusConfig[selectedOrder.status]?.label || selectedOrder.status}
                  </span>
                </div>

                <p className="text-xs text-muted-foreground mb-4">
                  {selectedOrder.table
                    ? `${selectedOrder.table.table_number} (${selectedOrder.table.name || "Dine-in"})`
                    : "Direct Walk-in / Takeaway"}{" "}
                  · <span className="font-semibold text-foreground">{selectedOrder.guest_name}</span>
                </p>

                {/* Items breakdown */}
                <div className="space-y-2 mb-4 border-b border-border/40 pb-3 max-h-56 overflow-y-auto">
                  {selectedOrder.items?.map((item, idx) => (
                    <div key={idx} className="flex justify-between items-start text-xs">
                      <div>
                        <span className="font-semibold text-foreground">
                          {item.quantity}x {item.item_name}
                        </span>
                        {(item.notes || item.special_notes) && (
                          <p className="text-[11px] text-muted-foreground italic">
                            Note: {item.notes || item.special_notes}
                          </p>
                        )}
                      </div>
                      <span className="font-mono text-muted-foreground">
                        ${(Number(item.unit_price) * item.quantity).toFixed(2)}
                      </span>
                    </div>
                  ))}
                </div>

                {/* Totals */}
                <div className="space-y-1.5 text-xs border-b border-border/40 pb-3 mb-4">
                  <div className="flex justify-between text-muted-foreground">
                    <span>Subtotal</span>
                    <span className="font-mono">${Number(selectedOrder.subtotal || 0).toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between text-muted-foreground">
                    <span>Tax (8%)</span>
                    <span className="font-mono">${Number(selectedOrder.tax || 0).toFixed(2)}</span>
                  </div>
                  {Number(selectedOrder.service_charge || 0) > 0 && (
                    <div className="flex justify-between text-muted-foreground">
                      <span>Service Charge</span>
                      <span className="font-mono">
                        ${Number(selectedOrder.service_charge).toFixed(2)}
                      </span>
                    </div>
                  )}
                  <div className="flex justify-between font-bold text-sm text-foreground pt-1">
                    <span>Total</span>
                    <span className="font-mono">${Number(selectedOrder.total || 0).toFixed(2)}</span>
                  </div>
                </div>

                {/* Stepper Progress */}
                <div className="space-y-2 mb-5">
                  <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Kitchen & Floor Lifecycle
                  </p>
                  <div className="flex gap-1">
                    {statusOrder.map((s) => {
                      const isPastOrCurrent =
                        statusOrder.indexOf(selectedOrder.status) >= statusOrder.indexOf(s);
                      return (
                        <div
                          key={s}
                          title={s}
                          className={`flex-1 h-2 rounded-full transition-all ${
                            selectedOrder.status === "cancelled"
                              ? "bg-destructive/30"
                              : isPastOrCurrent
                              ? "bg-primary"
                              : "bg-muted"
                          }`}
                        />
                      );
                    })}
                  </div>
                  <div className="flex justify-between text-[10px] text-muted-foreground capitalize">
                    <span>Placed</span>
                    <span>Ready</span>
                    <span>Completed</span>
                  </div>
                </div>

                {/* Actions */}
                {selectedOrder.status !== "completed" && selectedOrder.status !== "cancelled" ? (
                  <div className="grid grid-cols-2 gap-2">
                    <Button
                      onClick={() => handleAdvanceStatus(selectedOrder)}
                      className="gradient-warm text-primary-foreground font-semibold text-xs h-9 gap-1"
                    >
                      Advance Status <ChevronRight className="w-3.5 h-3.5" />
                    </Button>
                    <Button
                      variant="outline"
                      onClick={() => handleCancelOrder(selectedOrder)}
                      className="text-xs h-9 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                      <XCircle className="w-3.5 h-3.5 mr-1" /> Cancel
                    </Button>
                  </div>
                ) : (
                  <p className="text-center text-xs text-muted-foreground italic py-1">
                    Order is {selectedOrder.status}
                  </p>
                )}
              </motion.div>
            ) : (
              <div className="glass-card p-10 text-center rounded-2xl border border-border/50">
                <Eye className="w-8 h-8 text-muted-foreground/30 mx-auto mb-2" />
                <p className="text-muted-foreground text-xs">Select an order to inspect details</p>
              </div>
            )}
          </AnimatePresence>
        </div>
      </div>

      {/* Manual Walk-in POS Order Modal */}
      <Dialog open={isPosOpen} onOpenChange={setIsPosOpen}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] flex flex-col p-6">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ShoppingBag className="w-5 h-5 text-primary" /> Create Walk-in POS Order
            </DialogTitle>
            <DialogDescription>
              Direct counter order entry with automatic kitchen routing and table assignment.
            </DialogDescription>
          </DialogHeader>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 py-2 overflow-y-auto flex-1">
            {/* Left: Setup & Menu Browser */}
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="text-xs font-semibold text-foreground mb-1 block">Table</label>
                  <select
                    value={posTableId}
                    onChange={(e) => setPosTableId(e.target.value)}
                    className="w-full text-xs rounded-xl border border-border bg-background p-2"
                  >
                    <option value="">Takeaway / Counter</option>
                    {tables.map((t) => (
                      <option key={t.id} value={t.id}>
                        {t.table_number} ({t.name} - {t.zone})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-xs font-semibold text-foreground mb-1 block">Guest Name</label>
                  <Input
                    value={posGuestName}
                    onChange={(e) => setPosGuestName(e.target.value)}
                    placeholder="Guest name"
                    className="text-xs h-8"
                  />
                </div>
              </div>

              <div>
                <label className="text-xs font-semibold text-foreground mb-1 block">Search Menu</label>
                <div className="relative">
                  <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    value={posSearch}
                    onChange={(e) => setPosSearch(e.target.value)}
                    placeholder="Search dishes, drinks..."
                    className="pl-8 text-xs h-8 rounded-xl"
                  />
                </div>
              </div>

              {/* Menu items list */}
              <div className="space-y-1.5 max-h-64 overflow-y-auto pr-1">
                {menuItems
                  .filter((m) => m.name.toLowerCase().includes(posSearch.toLowerCase()))
                  .map((item) => (
                    <div
                      key={item.id}
                      onClick={() => addToPosCart(item)}
                      className="p-2.5 rounded-xl border border-border/50 hover:bg-primary/5 hover:border-primary/40 cursor-pointer transition-all flex items-center justify-between"
                    >
                      <div>
                        <p className="text-xs font-semibold text-foreground">{item.name}</p>
                        <p className="text-[11px] text-muted-foreground">
                          {item.category?.name || "Kitchen"} · ${Number(item.price).toFixed(2)}
                        </p>
                      </div>
                      <Button size="sm" variant="ghost" className="h-7 w-7 p-0 text-primary">
                        <Plus className="w-4 h-4" />
                      </Button>
                    </div>
                  ))}
              </div>
            </div>

            {/* Right: Cart & Review */}
            <div className="bg-muted/30 p-4 rounded-2xl border border-border/50 flex flex-col justify-between">
              <div>
                <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3">
                  Current Order Items ({posCart.length})
                </h4>

                {posCart.length === 0 ? (
                  <p className="text-xs text-muted-foreground text-center py-10 italic">
                    Tap dishes on the left to add items to this order.
                  </p>
                ) : (
                  <div className="space-y-2 max-h-56 overflow-y-auto pr-1 mb-3">
                    {posCart.map((c) => (
                      <div
                        key={c.item.id}
                        className="bg-background p-2.5 rounded-xl border border-border/60 flex items-center justify-between text-xs"
                      >
                        <div className="flex-1 mr-2">
                          <p className="font-semibold text-foreground">{c.item.name}</p>
                          <p className="text-muted-foreground font-mono">
                            ${Number(c.item.price).toFixed(2)}
                          </p>
                        </div>
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => updateCartQty(c.item.id, -1)}
                            className="w-6 h-6 rounded-lg bg-muted flex items-center justify-center font-bold text-foreground hover:bg-muted/80"
                          >
                            -
                          </button>
                          <span className="font-semibold text-xs">{c.quantity}</span>
                          <button
                            onClick={() => updateCartQty(c.item.id, 1)}
                            className="w-6 h-6 rounded-lg bg-muted flex items-center justify-center font-bold text-foreground hover:bg-muted/80"
                          >
                            +
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                <div>
                  <label className="text-xs font-semibold text-foreground mb-1 block">Order Notes</label>
                  <Input
                    value={posNotes}
                    onChange={(e) => setPosNotes(e.target.value)}
                    placeholder="e.g. Rush order / allergy info"
                    className="text-xs h-8"
                  />
                </div>
              </div>

              {/* Totals & Submit */}
              <div className="border-t border-border/60 pt-3 mt-4 space-y-2">
                <div className="flex justify-between text-xs text-muted-foreground">
                  <span>Subtotal</span>
                  <span className="font-mono">${cartSubtotal.toFixed(2)}</span>
                </div>
                <div className="flex justify-between text-xs text-muted-foreground">
                  <span>Tax (8%)</span>
                  <span className="font-mono">${(cartSubtotal * 0.08).toFixed(2)}</span>
                </div>
                <div className="flex justify-between font-bold text-sm text-foreground">
                  <span>Estimated Total</span>
                  <span className="font-mono">${(cartSubtotal * 1.08).toFixed(2)}</span>
                </div>

                <Button
                  onClick={handleCreatePosOrder}
                  disabled={isSubmittingPos || posCart.length === 0}
                  className="w-full gradient-warm text-primary-foreground font-semibold text-xs h-9 mt-2"
                >
                  {isSubmittingPos ? "Submitting to Kitchen..." : "Dispatch Order to Kitchen"}
                </Button>
              </div>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
