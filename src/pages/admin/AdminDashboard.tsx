import { useState, useEffect } from "react";
import { motion } from "framer-motion";
import { Link } from "react-router-dom";
import {
  ShoppingCart,
  DollarSign,
  Grid3X3,
  TrendingUp,
  ChefHat,
  Clock,
  ArrowUpRight,
  UtensilsCrossed,
  AlertTriangle,
  RotateCcw,
  QrCode,
  Users,
  ShieldCheck,
  CreditCard,
  RefreshCw,
  Sparkles,
} from "lucide-react";
import { AdminLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
import { StatCard } from "@/components/StatCard";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { toast } from "@/hooks/use-toast";

interface DashboardData {
  tenant?: {
    id: number;
    name: string;
    slug: string;
    currency: string;
  };
  stats?: {
    total_orders: number;
    revenue_today: string;
    available_tables: string;
    occupied_tables: number;
    low_stock_alerts: number;
  };
  recent_orders?: Array<{
    id: number;
    order_number: string;
    status: string;
    total: string;
    created_at: string;
    guest_name?: string;
    table?: {
      table_number: string;
      name?: string;
    };
    items?: Array<{
      item_name: string;
      quantity: number;
    }>;
  }>;
  popular_dishes?: Array<{
    id: number;
    name: string;
    price: string;
    category?: { name: string };
  }>;
}

const statusColors: Record<string, string> = {
  placed: "bg-amber-500/10 text-amber-600",
  accepted: "bg-blue-500/10 text-blue-600",
  preparing: "bg-primary/10 text-primary",
  ready: "bg-emerald-500/10 text-emerald-600",
  served: "bg-indigo-500/10 text-indigo-600",
  completed: "bg-muted text-muted-foreground",
  cancelled: "bg-destructive/10 text-destructive",
};

const container = { hidden: {}, show: { transition: { staggerChildren: 0.06 } } };
const item = { hidden: { opacity: 0, y: 15 }, show: { opacity: 1, y: 0 } };

export default function AdminDashboard() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isResetting, setIsResetting] = useState(false);

  const loadStats = async () => {
    setIsLoading(true);
    try {
      const res = await api.getDashboardStats();
      if (res) setData(res);
    } catch (err) {
      console.error("Failed to load dashboard stats:", err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadStats();
    const interval = setInterval(loadStats, 10000);
    return () => clearInterval(interval);
  }, []);

  const handleResetDemo = async () => {
    if (
      !window.confirm(
        "Are you sure you want to reset all demo data? This will restore initial tables, orders, sessions, and payments for a clean showcase."
      )
    ) {
      return;
    }

    setIsResetting(true);
    try {
      await api.resetDemoData();
      toast({
        title: "Demo State Reset",
        description: "Application database has been refreshed to pristine initial state.",
      });
      await loadStats();
    } catch (err: any) {
      toast({
        title: "Reset Failed",
        description: err.message || "Could not reset demo database.",
        variant: "destructive",
      });
    } finally {
      setIsResetting(false);
    }
  };

  const stats = data?.stats;
  const recentOrders = data?.recent_orders || [];
  const popularDishes = data?.popular_dishes || [];

  const preparingCount = recentOrders.filter((o) => o.status === "preparing").length;
  const readyCount = recentOrders.filter((o) => o.status === "ready").length;
  const placedCount = recentOrders.filter((o) => o.status === "placed").length;

  return (
    <AdminLayout>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <PageHeader
          title={data?.tenant?.name ? `${data.tenant.name} · Admin Control` : "Café X Admin Dashboard"}
          subtitle="Real-time multi-tenant telemetry, floor table occupancy, and kitchen throughput"
        />
        <div className="flex items-center gap-2 self-start sm:self-center">
          <Button
            variant="outline"
            size="sm"
            onClick={loadStats}
            disabled={isLoading}
            className="gap-2"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? "animate-spin" : ""}`} />
            Refresh
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={handleResetDemo}
            disabled={isResetting}
            className="gap-2 text-xs font-semibold"
          >
            <RotateCcw className={`w-3.5 h-3.5 ${isResetting ? "animate-spin" : ""}`} />
            {isResetting ? "Resetting..." : "Reset Showcase Data"}
          </Button>
        </div>
      </div>

      {/* Quick Showcase Launchpad */}
      <motion.div
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        className="glass-card p-4 rounded-2xl border border-primary/20 bg-primary/5 mb-8 flex flex-col md:flex-row items-center justify-between gap-3"
      >
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl gradient-warm flex items-center justify-center text-primary-foreground shadow-md">
            <Sparkles className="w-5 h-5" />
          </div>
          <div>
            <h3 className="font-display font-bold text-sm text-foreground">
              Café X Interactive Showcase Modules
            </h3>
            <p className="text-xs text-muted-foreground">
              Experience the end-to-end QR dining, waiter floor, kitchen display, and ledger audit flows.
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Link to="/t/table-7">
            <Button size="sm" variant="outline" className="text-xs h-8 gap-1.5 rounded-xl border-primary/40 hover:bg-primary/10">
              <QrCode className="w-3.5 h-3.5 text-primary" /> Table 7 QR Menu
            </Button>
          </Link>
          <Link to="/waiter">
            <Button size="sm" variant="outline" className="text-xs h-8 gap-1.5 rounded-xl border-indigo-400/40 hover:bg-indigo-500/10">
              <Users className="w-3.5 h-3.5 text-indigo-500" /> Waiter Floor
            </Button>
          </Link>
          <Link to="/kitchen/display">
            <Button size="sm" variant="outline" className="text-xs h-8 gap-1.5 rounded-xl border-amber-400/40 hover:bg-amber-500/10">
              <ChefHat className="w-3.5 h-3.5 text-amber-500" /> Kitchen KDS
            </Button>
          </Link>
          <Link to="/admin/payments">
            <Button size="sm" variant="outline" className="text-xs h-8 gap-1.5 rounded-xl border-emerald-400/40 hover:bg-emerald-500/10">
              <CreditCard className="w-3.5 h-3.5 text-emerald-500" /> Payments Ledger
            </Button>
          </Link>
          <Link to="/admin/audit-logs">
            <Button size="sm" variant="outline" className="text-xs h-8 gap-1.5 rounded-xl border-purple-400/40 hover:bg-purple-500/10">
              <ShieldCheck className="w-3.5 h-3.5 text-purple-500" /> Audit Trail
            </Button>
          </Link>
        </div>
      </motion.div>

      {/* KPI Stat Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard
          icon={ShoppingCart}
          title="Total Orders"
          value={stats ? stats.total_orders.toString() : "0"}
          change="+15%"
          changeType="positive"
          delay={0}
        />
        <StatCard
          icon={DollarSign}
          title="Revenue (Gross)"
          value={stats ? stats.revenue_today : "$0.00"}
          change="+8%"
          changeType="positive"
          delay={0.1}
        />
        <StatCard
          icon={Grid3X3}
          title="Table Occupancy"
          value={stats ? `${stats.occupied_tables} Occupied` : "0"}
          change={stats ? `${stats.available_tables} Free` : "0/0"}
          changeType="neutral"
          delay={0.2}
        />
        <StatCard
          icon={TrendingUp}
          title="Avg. Ticket"
          value={
            stats && stats.total_orders > 0
              ? `$${(
                  parseFloat(stats.revenue_today.replace(/[^0-9.]/g, "")) /
                  stats.total_orders
                ).toFixed(2)}`
              : "$0.00"
          }
          change="+5%"
          changeType="positive"
          delay={0.3}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Recent Live Orders */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.3 }}
          className="lg:col-span-2 glass-card-elevated rounded-2xl overflow-hidden border border-border/50"
        >
          <div className="p-5 border-b border-border/50 flex items-center justify-between">
            <div>
              <h2 className="font-display font-semibold text-lg text-foreground">Live Floor Orders</h2>
              <p className="text-xs text-muted-foreground">Orders synchronized with kitchen & floor</p>
            </div>
            <Link
              to="/admin/orders"
              className="text-xs text-primary flex items-center gap-1 font-semibold hover:underline"
            >
              Order POS Terminal <ArrowUpRight className="w-3.5 h-3.5" />
            </Link>
          </div>

          <motion.div variants={container} initial="hidden" animate="show">
            {recentOrders.length === 0 ? (
              <p className="p-8 text-center text-xs text-muted-foreground italic">
                No orders active right now. Place an order via QR or POS.
              </p>
            ) : (
              recentOrders.map((order) => (
                <motion.div
                  key={order.id}
                  variants={item}
                  className="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors border-b border-border/30 last:border-0"
                >
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="w-9 h-9 rounded-xl bg-primary/10 flex items-center justify-center flex-shrink-0 text-primary">
                      <UtensilsCrossed className="w-4 h-4" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-foreground">
                        {order.order_number} ·{" "}
                        {order.table?.table_number ? order.table.table_number : "Walk-in"}{" "}
                        <span className="text-xs font-normal text-muted-foreground">
                          ({order.guest_name || "Guest"})
                        </span>
                      </p>
                      <p className="text-xs text-muted-foreground truncate">
                        {order.items?.map((i) => `${i.quantity}x ${i.item_name}`).join(", ") ||
                          "No items"}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3 flex-shrink-0 ml-3">
                    <span
                      className={`text-[11px] font-semibold px-2.5 py-0.5 rounded-full capitalize ${
                        statusColors[order.status] || "bg-muted text-muted-foreground"
                      }`}
                    >
                      {order.status}
                    </span>
                    <span className="text-sm font-semibold font-mono text-foreground hidden sm:block">
                      ${Number(order.total || 0).toFixed(2)}
                    </span>
                  </div>
                </motion.div>
              ))
            )}
          </motion.div>
        </motion.div>

        {/* Sidebar Widgets */}
        <div className="space-y-6">
          {/* Kitchen Throughput Status */}
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.4 }}
            className="glass-card-elevated p-5 rounded-2xl border border-border/50"
          >
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <ChefHat className="w-4 h-4 text-primary" />
                <h2 className="font-display font-semibold text-foreground">Kitchen Queue</h2>
              </div>
              <Link to="/kitchen/display" className="text-[11px] text-primary hover:underline">
                Open KDS →
              </Link>
            </div>
            <div className="grid grid-cols-3 gap-2">
              <div className="bg-amber-500/10 rounded-xl p-2.5 text-center">
                <p className="text-xl font-display font-bold text-amber-600">{placedCount}</p>
                <p className="text-[10px] text-muted-foreground">Placed</p>
              </div>
              <div className="bg-primary/10 rounded-xl p-2.5 text-center">
                <p className="text-xl font-display font-bold text-primary">{preparingCount}</p>
                <p className="text-[10px] text-muted-foreground">Cooking</p>
              </div>
              <div className="bg-emerald-500/10 rounded-xl p-2.5 text-center">
                <p className="text-xl font-display font-bold text-emerald-600">{readyCount}</p>
                <p className="text-[10px] text-muted-foreground">Ready</p>
              </div>
            </div>
          </motion.div>

          {/* Popular Dishes */}
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.5 }}
            className="glass-card-elevated p-5 rounded-2xl border border-border/50"
          >
            <div className="flex items-center justify-between mb-3">
              <h2 className="font-display font-semibold text-base text-foreground">Featured Specialties</h2>
              <Link to="/admin/menu" className="text-[11px] text-primary hover:underline">
                Manage Menu →
              </Link>
            </div>
            <div className="space-y-2.5">
              {popularDishes.map((dish, i) => (
                <div key={dish.id} className="flex items-center justify-between text-xs">
                  <div className="flex items-center gap-2">
                    <span className="w-5 h-5 rounded-md bg-muted flex items-center justify-center font-bold text-[10px] text-foreground">
                      {i + 1}
                    </span>
                    <span className="font-medium text-foreground">{dish.name}</span>
                  </div>
                  <span className="font-mono text-muted-foreground">
                    ${Number(dish.price).toFixed(2)}
                  </span>
                </div>
              ))}
            </div>
          </motion.div>
        </div>
      </div>
    </AdminLayout>
  );
}
