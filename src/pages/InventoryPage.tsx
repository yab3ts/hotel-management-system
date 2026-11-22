import React, { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { 
  Package, 
  AlertTriangle, 
  TrendingDown, 
  Plus, 
  Search, 
  Truck, 
  Pencil, 
  RefreshCw, 
  CheckCircle2, 
  X, 
  Save 
} from "lucide-react";
import { AdminLayout as AppLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface InventoryItemData {
  id: number;
  name: string;
  category?: string;
  stock: number;
  threshold: number;
  unit: string;
  cost_per_unit?: number;
  supplier?: string;
  status: "critical" | "low" | "ok";
  updated_at?: string;
}

const fallbackInventory: InventoryItemData[] = [
  { id: 1, name: "Wagyu Beef (A5 Cut)", category: "meat", stock: 2, threshold: 5, unit: "kg", supplier: "Premium Meats Co.", cost_per_unit: 45, status: "critical" },
  { id: 2, name: "Truffle Oil Extra Virgin", category: "produce", stock: 1, threshold: 3, unit: "bottles", supplier: "Gourmet Imports", cost_per_unit: 28, status: "critical" },
  { id: 3, name: "Fresh Atlantic Salmon", category: "seafood", stock: 3.5, threshold: 8, unit: "kg", supplier: "Ocean Fresh Ltd.", cost_per_unit: 22, status: "low" },
  { id: 4, name: "Buffalo Mozzarella DOP", category: "dairy", stock: 4, threshold: 6, unit: "kg", supplier: "Dairy Direct", cost_per_unit: 12, status: "low" },
  { id: 5, name: "Fresh Organic Basil", category: "produce", stock: 0.5, threshold: 2, unit: "kg", supplier: "Herb Garden Co.", cost_per_unit: 6, status: "critical" },
  { id: 6, name: "All-Purpose Italian Flour 00", category: "dry_goods", stock: 25, threshold: 10, unit: "kg", supplier: "Baker's Best", cost_per_unit: 1.8, status: "ok" },
  { id: 7, name: "Heavy Whipping Cream", category: "dairy", stock: 6, threshold: 4, unit: "L", supplier: "Dairy Direct", cost_per_unit: 4.5, status: "ok" },
  { id: 8, name: "Espresso Coffee Beans", category: "beverages", stock: 18, threshold: 10, unit: "kg", supplier: "Artisan Roasters", cost_per_unit: 16.5, status: "ok" },
];

const statusStyles: Record<string, { bg: string; text: string; label: string }> = {
  critical: { bg: "bg-destructive/10 border-destructive/20", text: "text-destructive", label: "Critical" },
  low: { bg: "bg-warning/10 border-warning/20", text: "text-warning", label: "Low Stock" },
  ok: { bg: "bg-success/10 border-success/20", text: "text-success", label: "In Stock" },
};

export default function InventoryPage() {
  const [items, setItems] = useState<InventoryItemData[]>(fallbackInventory);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [filterStatus, setFilterStatus] = useState<string>("all");

  // Modals
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [adjustItem, setAdjustItem] = useState<InventoryItemData | null>(null);
  const [adjustStock, setAdjustStock] = useState<number>(0);
  const [saving, setSaving] = useState(false);

  // Form state
  const [newItem, setNewItem] = useState({
    item_name: "",
    category: "produce",
    current_stock: 10,
    minimum_stock: 5,
    unit: "kg",
    cost_per_unit: 10,
    supplier: "Direct Supplier",
  });

  const fetchInventory = async () => {
    try {
      setLoading(true);
      const res = await api.getInventory();
      if (res?.items && Array.isArray(res.items)) {
        setItems(res.items);
      }
    } catch (err: any) {
      console.warn("Could not fetch inventory from API, using fallback:", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchInventory();
  }, []);

  const handleCreateItem = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      await api.createInventoryItem(newItem);
      toast({
        title: "Item Added",
        description: `${newItem.item_name} added to inventory.`,
      });
      setAddModalOpen(false);
      setNewItem({
        item_name: "",
        category: "produce",
        current_stock: 10,
        minimum_stock: 5,
        unit: "kg",
        cost_per_unit: 10,
        supplier: "Direct Supplier",
      });
      fetchInventory();
    } catch (err: any) {
      toast({
        title: "Creation Failed",
        description: err.message || "Could not add inventory item.",
        variant: "destructive",
      });
    } finally {
      setSaving(false);
    }
  };

  const handleAdjustStock = async () => {
    if (!adjustItem) return;
    try {
      setSaving(true);
      await api.updateInventoryItem(adjustItem.id, {
        current_stock: adjustStock,
      });
      toast({
        title: "Stock Updated",
        description: `${adjustItem.name} stock updated to ${adjustStock} ${adjustItem.unit}.`,
      });
      setAdjustItem(null);
      fetchInventory();
    } catch (err: any) {
      toast({
        title: "Update Failed",
        description: err.message || "Could not update stock level.",
        variant: "destructive",
      });
    } finally {
      setSaving(false);
    }
  };

  const filteredItems = items.filter((i) => {
    const matchesSearch = i.name.toLowerCase().includes(search.toLowerCase()) || 
      (i.category && i.category.toLowerCase().includes(search.toLowerCase()));
    const matchesStatus = filterStatus === "all" || i.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const criticalCount = items.filter((i) => i.status === "critical").length;
  const lowCount = items.filter((i) => i.status === "low").length;

  return (
    <AppLayout>
      <PageHeader
        title="Inventory & Recipe Ingredients"
        subtitle="Track pantry stock, trigger threshold alerts, and auto-deduct recipe ingredients on dish preparation"
        actions={
          <div className="flex gap-2">
            <button
              onClick={fetchInventory}
              className="p-2.5 rounded-xl border border-border bg-card text-muted-foreground hover:text-foreground"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? "animate-spin" : ""}`} />
            </button>
            <motion.button
              whileHover={{ scale: 1.05 }}
              whileTap={{ scale: 0.95 }}
              onClick={() => setAddModalOpen(true)}
              className="gradient-warm text-primary-foreground px-4 py-2.5 rounded-xl text-sm font-semibold flex items-center gap-2 shadow-lg"
            >
              <Plus className="w-4 h-4" /> Add Ingredient
            </motion.button>
          </div>
        }
      />

      {/* Metric Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <motion.div initial={{ opacity: 0, y: 15 }} animate={{ opacity: 1, y: 0 }} className="glass-card p-5 flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-destructive/10 flex items-center justify-center">
            <AlertTriangle className="w-6 h-6 text-destructive" />
          </div>
          <div>
            <p className="text-2xl font-display font-bold text-foreground">{criticalCount}</p>
            <p className="text-sm text-muted-foreground">Critical Threshold Items</p>
          </div>
        </motion.div>
        <motion.div initial={{ opacity: 0, y: 15 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }} className="glass-card p-5 flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-warning/10 flex items-center justify-center">
            <TrendingDown className="w-6 h-6 text-warning" />
          </div>
          <div>
            <p className="text-2xl font-display font-bold text-foreground">{lowCount}</p>
            <p className="text-sm text-muted-foreground">Low Stock Warnings</p>
          </div>
        </motion.div>
        <motion.div initial={{ opacity: 0, y: 15 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }} className="glass-card p-5 flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-success/10 flex items-center justify-center">
            <Package className="w-6 h-6 text-success" />
          </div>
          <div>
            <p className="text-2xl font-display font-bold text-foreground">{items.length}</p>
            <p className="text-sm text-muted-foreground">Active Tracked Ingredients</p>
          </div>
        </motion.div>
      </div>

      {/* Filter and Search Bar */}
      <div className="flex flex-col sm:flex-row gap-3 mb-6 items-center justify-between">
        <div className="relative flex-1 w-full">
          <Search className="w-4 h-4 text-muted-foreground absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search ingredients, cuts, supplies, or category..."
            className="w-full bg-secondary/50 border border-border rounded-xl pl-10 pr-4 py-2 text-xs text-foreground placeholder:text-muted-foreground"
          />
        </div>
        <div className="flex gap-1.5 self-start sm:self-auto">
          {["all", "critical", "low", "ok"].map((st) => (
            <button
              key={st}
              onClick={() => setFilterStatus(st)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold capitalize transition-colors ${
                filterStatus === st
                  ? "bg-primary text-primary-foreground shadow"
                  : "bg-secondary text-muted-foreground hover:text-foreground"
              }`}
            >
              {st === "ok" ? "In Stock" : st}
            </button>
          ))}
        </div>
      </div>

      {/* Inventory Table */}
      <div className="glass-card-elevated overflow-hidden rounded-2xl">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="text-xs text-muted-foreground uppercase tracking-wider border-b border-border/50">
                <th className="text-left p-4">Ingredient / Supply</th>
                <th className="text-right p-4">Current Stock</th>
                <th className="text-right p-4">Min. Threshold</th>
                <th className="text-left p-4">Supplier</th>
                <th className="text-center p-4">Status</th>
                <th className="text-right p-4">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center p-8 text-xs text-muted-foreground">
                    No inventory ingredients match your filter.
                  </td>
                </tr>
              ) : (
                filteredItems.map((item, i) => {
                  const style = statusStyles[item.status] || statusStyles.ok;
                  return (
                    <motion.tr
                      key={item.id || item.name}
                      initial={{ opacity: 0, x: -10 }}
                      animate={{ opacity: 1, x: 0 }}
                      transition={{ delay: i * 0.03 }}
                      className="border-t border-border/30 hover:bg-muted/30 transition-colors"
                    >
                      <td className="p-4">
                        <span className="font-semibold text-foreground text-xs block">{item.name}</span>
                        {item.category && (
                          <span className="text-[10px] text-muted-foreground uppercase tracking-wider">{item.category}</span>
                        )}
                      </td>
                      <td className="p-4 text-right">
                        <span className="font-mono font-bold text-foreground text-xs">
                          {item.stock} {item.unit}
                        </span>
                      </td>
                      <td className="p-4 text-right">
                        <span className="font-mono text-muted-foreground text-xs">
                          {item.threshold} {item.unit}
                        </span>
                      </td>
                      <td className="p-4 text-xs text-muted-foreground">
                        {item.supplier || "Local Vendor"}
                      </td>
                      <td className="p-4 text-center">
                        <span className={`inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold border ${style.bg} ${style.text}`}>
                          {style.label}
                        </span>
                      </td>
                      <td className="p-4 text-right">
                        <button
                          onClick={() => {
                            setAdjustItem(item);
                            setAdjustStock(item.stock);
                          }}
                          className="px-2.5 py-1 rounded-lg bg-secondary hover:bg-muted text-xs font-semibold text-foreground inline-flex items-center gap-1 border border-border"
                        >
                          <Pencil className="w-3 h-3 text-primary" /> Adjust / Restock
                        </button>
                      </td>
                    </motion.tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Adjust Stock Modal */}
      <AnimatePresence>
        {adjustItem && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-card border border-border rounded-3xl max-w-sm w-full p-6 shadow-2xl relative"
            >
              <button
                onClick={() => setAdjustItem(null)}
                className="absolute top-4 right-4 text-muted-foreground hover:text-foreground"
              >
                <X className="w-5 h-5" />
              </button>

              <h3 className="font-display font-bold text-base text-foreground mb-1">
                Adjust Stock: {adjustItem.name}
              </h3>
              <p className="text-xs text-muted-foreground mb-4">
                Update stock on hand after deliveries, daily inventory counts, or prep usage.
              </p>

              <div className="space-y-3 mb-5 text-xs">
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">New Stock Level ({adjustItem.unit})</label>
                  <input
                    type="number"
                    step="0.1"
                    min="0"
                    value={adjustStock}
                    onChange={(e) => setAdjustStock(parseFloat(e.target.value) || 0)}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-mono font-bold"
                  />
                </div>

                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => setAdjustStock((prev) => Math.max(0, prev - 1))}
                    className="flex-1 py-1.5 rounded-lg bg-secondary border border-border text-xs font-bold text-foreground"
                  >
                    -1 {adjustItem.unit}
                  </button>
                  <button
                    type="button"
                    onClick={() => setAdjustStock((prev) => prev + 5)}
                    className="flex-1 py-1.5 rounded-lg bg-secondary border border-border text-xs font-bold text-foreground"
                  >
                    +5 {adjustItem.unit}
                  </button>
                  <button
                    type="button"
                    onClick={() => setAdjustStock((prev) => prev + 10)}
                    className="flex-1 py-1.5 rounded-lg bg-secondary border border-border text-xs font-bold text-foreground"
                  >
                    +10 {adjustItem.unit}
                  </button>
                </div>
              </div>

              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setAdjustItem(null)}
                  className="flex-1 py-2.5 bg-secondary text-muted-foreground text-xs font-semibold rounded-xl"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={saving}
                  onClick={handleAdjustStock}
                  className="flex-1 py-2.5 gradient-warm text-primary-foreground font-bold text-xs rounded-xl shadow disabled:opacity-50"
                >
                  {saving ? "Saving..." : "Save Stock"}
                </button>
              </div>
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* Add Item Modal */}
      <AnimatePresence>
        {addModalOpen && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-card border border-border rounded-3xl max-w-md w-full p-6 shadow-2xl relative"
            >
              <button
                onClick={() => setAddModalOpen(false)}
                className="absolute top-4 right-4 text-muted-foreground hover:text-foreground"
              >
                <X className="w-5 h-5" />
              </button>

              <h3 className="font-display font-bold text-lg text-foreground mb-1">
                Add Ingredient / Supply
              </h3>
              <p className="text-xs text-muted-foreground mb-4">
                Record new food ingredient with minimum safety thresholds.
              </p>

              <form onSubmit={handleCreateItem} className="space-y-3 text-xs">
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">Ingredient Name</label>
                  <input
                    type="text"
                    required
                    value={newItem.item_name}
                    onChange={(e) => setNewItem({ ...newItem, item_name: e.target.value })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-medium"
                    placeholder="e.g. San Marzano Tomatoes"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="text-muted-foreground font-semibold block mb-1">Category</label>
                    <select
                      value={newItem.category}
                      onChange={(e) => setNewItem({ ...newItem, category: e.target.value })}
                      className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground"
                    >
                      <option value="meat">Meat & Steaks</option>
                      <option value="seafood">Seafood</option>
                      <option value="dairy">Dairy & Cheeses</option>
                      <option value="produce">Fresh Produce</option>
                      <option value="dry_goods">Dry Goods & Flour</option>
                      <option value="beverages">Beverages & Coffee</option>
                    </select>
                  </div>
                  <div>
                    <label className="text-muted-foreground font-semibold block mb-1">Measurement Unit</label>
                    <input
                      type="text"
                      required
                      value={newItem.unit}
                      onChange={(e) => setNewItem({ ...newItem, unit: e.target.value })}
                      className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground"
                      placeholder="kg, L, bottles, pcs"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="text-muted-foreground font-semibold block mb-1">Initial Stock</label>
                    <input
                      type="number"
                      step="0.1"
                      required
                      value={newItem.current_stock}
                      onChange={(e) => setNewItem({ ...newItem, current_stock: parseFloat(e.target.value) || 0 })}
                      className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-mono"
                    />
                  </div>
                  <div>
                    <label className="text-muted-foreground font-semibold block mb-1">Min. Alert Threshold</label>
                    <input
                      type="number"
                      step="0.1"
                      required
                      value={newItem.minimum_stock}
                      onChange={(e) => setNewItem({ ...newItem, minimum_stock: parseFloat(e.target.value) || 0 })}
                      className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-mono"
                    />
                  </div>
                </div>

                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">Supplier / Vendor</label>
                  <input
                    type="text"
                    value={newItem.supplier}
                    onChange={(e) => setNewItem({ ...newItem, supplier: e.target.value })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground"
                  />
                </div>

                <div className="flex gap-2 pt-3">
                  <button
                    type="button"
                    onClick={() => setAddModalOpen(false)}
                    className="flex-1 py-2.5 bg-secondary text-muted-foreground text-xs font-semibold rounded-xl"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={saving}
                    className="flex-1 py-2.5 gradient-warm text-primary-foreground font-bold text-xs rounded-xl shadow disabled:opacity-50"
                  >
                    {saving ? "Adding..." : "Add Ingredient"}
                  </button>
                </div>
              </form>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </AppLayout>
  );
}
