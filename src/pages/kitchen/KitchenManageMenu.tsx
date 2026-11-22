import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Search, Eye, EyeOff, RefreshCw, AlertCircle } from "lucide-react";
import { KitchenLayout } from "@/components/KitchenLayout";
import { PageHeader } from "@/components/PageHeader";
import { toast } from "@/hooks/use-toast";
import { api } from "@/lib/api";

interface MenuItemEntry {
  id: number;
  name: string;
  category: string;
  price: string;
  emoji?: string;
  available: boolean;
  image_url?: string;
}

const fallbackMenuItems: MenuItemEntry[] = [
  { id: 1, name: "Wagyu Steak", category: "Dinner", price: "€85.00", emoji: "🥩", available: true },
  { id: 2, name: "Grilled Salmon", category: "Lunch", price: "€45.00", emoji: "🐟", available: true },
  { id: 3, name: "Margherita Pizza", category: "Lunch", price: "€20.00", emoji: "🍕", available: true },
  { id: 4, name: "Truffle Pasta", category: "Dinner", price: "€45.00", emoji: "🍝", available: true },
  { id: 5, name: "Avocado Toast", category: "Breakfast", price: "€14.00", emoji: "🥑", available: true },
  { id: 6, name: "Pancakes", category: "Breakfast", price: "€16.00", emoji: "🥞", available: true },
  { id: 7, name: "Iced Latte", category: "Drinks", price: "€6.00", emoji: "☕", available: true },
  { id: 8, name: "Tiramisu", category: "Desserts", price: "€12.00", emoji: "🍰", available: true },
  { id: 9, name: "Caesar Salad", category: "Lunch", price: "€18.00", emoji: "🥗", available: true },
  { id: 10, name: "Eggs Benedict", category: "Breakfast", price: "€17.00", emoji: "🍳", available: true },
  { id: 11, name: "Lobster Bisque", category: "Dinner", price: "€28.00", emoji: "🦞", available: false },
  { id: 12, name: "Fish & Chips", category: "Lunch", price: "€22.00", emoji: "🐠", available: true },
];

export default function KitchenManageMenu() {
  const [items, setItems] = useState<MenuItemEntry[]>(fallbackMenuItems);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [togglingId, setTogglingId] = useState<number | null>(null);

  const fetchMenuItems = async () => {
    try {
      setLoading(true);
      const data = await api.getMenu();
      if (Array.isArray(data) && data.length > 0) {
        const mapped: MenuItemEntry[] = data.map((d: any) => ({
          id: d.id,
          name: d.name,
          category: d.category?.name || "General",
          price: `€${parseFloat(d.price).toFixed(2)}`,
          emoji: d.category?.icon || "🍽️",
          available: Boolean(d.is_available),
          image_url: d.image_url,
        }));
        setItems(mapped);
      }
    } catch (err: any) {
      console.warn("Using fallback menu items due to:", err?.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchMenuItems();
  }, []);

  const toggleAvailability = async (id: number) => {
    const currentItem = items.find((i) => i.id === id);
    if (!currentItem) return;

    const newStatus = !currentItem.available;

    // Optimistic UI update
    setItems((prev) =>
      prev.map((item) => (item.id === id ? { ...item, available: newStatus } : item))
    );

    try {
      setTogglingId(id);
      await api.toggleMenuItemAvailability(id);
      toast({
        title: newStatus ? "Dish Available" : "Dish 86'd (Unavailable)",
        description: `${currentItem.name} is now ${newStatus ? "orderable" : "hidden/sold out"} on QR menus.`,
      });
    } catch (err: any) {
      // Revert if API call fails
      setItems((prev) =>
        prev.map((item) => (item.id === id ? { ...item, available: !newStatus } : item))
      );
      toast({
        title: "Update Failed",
        description: err.message || "Could not update availability on server.",
        variant: "destructive",
      });
    } finally {
      setTogglingId(null);
    }
  };

  const filtered = items.filter(
    (i) =>
      i.name.toLowerCase().includes(search.toLowerCase()) ||
      i.category.toLowerCase().includes(search.toLowerCase())
  );
  const unavailableCount = items.filter((i) => !i.available).length;

  return (
    <KitchenLayout>
      <PageHeader 
        title="Kitchen Menu & 86 List" 
        subtitle="Instantly 86 sold-out dishes or restore item availability across all QR menus" 
      />

      <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
        <div className="glass-card p-4 text-center">
          <p className="text-2xl font-display font-bold text-foreground">{items.length}</p>
          <p className="text-xs text-muted-foreground">Total Menu Items</p>
        </div>
        <div className="glass-card p-4 text-center">
          <p className="text-2xl font-display font-bold text-primary">{items.length - unavailableCount}</p>
          <p className="text-xs text-muted-foreground">Active in Kitchen</p>
        </div>
        <div className="glass-card p-4 text-center">
          <p className="text-2xl font-display font-bold text-destructive">{unavailableCount}</p>
          <p className="text-xs text-muted-foreground">86'd / Sold Out</p>
        </div>
      </div>

      <div className="flex items-center gap-3 mb-6">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <input
            type="text"
            placeholder="Search dishes by name or category..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full h-11 pl-10 pr-4 rounded-xl border border-input bg-background text-foreground text-sm focus:ring-2 focus:ring-ring outline-none"
          />
        </div>
        <button
          onClick={fetchMenuItems}
          disabled={loading}
          className="h-11 px-4 rounded-xl border border-input bg-card hover:bg-muted text-foreground text-xs font-semibold flex items-center gap-2 transition-colors"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin text-primary" : ""}`} />
          Refresh
        </button>
      </div>

      <div className="space-y-2.5">
        <AnimatePresence>
          {filtered.map((item) => (
            <motion.div
              key={item.id}
              layout
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, x: -100 }}
              className={`glass-card p-4 flex items-center gap-4 transition-all ${
                !item.available ? "opacity-60 bg-destructive/5 border-destructive/20" : ""
              }`}
            >
              {item.image_url ? (
                <img
                  src={item.image_url}
                  alt={item.name}
                  className="w-12 h-12 rounded-xl object-cover border border-border"
                />
              ) : (
                <span className="text-3xl">{item.emoji || "🍽️"}</span>
              )}

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <h3 className="font-semibold text-foreground text-sm truncate">{item.name}</h3>
                  {!item.available && (
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-destructive/20 text-destructive border border-destructive/30">
                      86'd (Sold Out)
                    </span>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {item.category} · <span className="font-semibold text-foreground">{item.price}</span>
                </p>
              </div>

              <motion.button
                whileTap={{ scale: 0.9 }}
                disabled={togglingId === item.id}
                onClick={() => toggleAvailability(item.id)}
                className={`px-3 py-2 rounded-xl text-xs font-bold flex items-center gap-2 transition-colors ${
                  item.available
                    ? "bg-primary/10 text-primary hover:bg-primary/20 border border-primary/20"
                    : "bg-destructive/15 text-destructive hover:bg-destructive/25 border border-destructive/30"
                }`}
                title={item.available ? "Click to 86 this dish" : "Click to mark as available"}
              >
                {togglingId === item.id ? (
                  <RefreshCw className="w-4 h-4 animate-spin" />
                ) : item.available ? (
                  <>
                    <Eye className="w-4 h-4" />
                    <span className="hidden sm:inline">Active</span>
                  </>
                ) : (
                  <>
                    <EyeOff className="w-4 h-4" />
                    <span className="hidden sm:inline">86'd</span>
                  </>
                )}
              </motion.button>
            </motion.div>
          ))}
        </AnimatePresence>

        {filtered.length === 0 && !loading && (
          <div className="text-center py-12 glass-card rounded-2xl">
            <AlertCircle className="w-8 h-8 text-muted-foreground mx-auto mb-2 opacity-50" />
            <p className="text-sm font-semibold text-foreground">No dishes found</p>
            <p className="text-xs text-muted-foreground">Try adjusting your search criteria.</p>
          </div>
        )}
      </div>
    </KitchenLayout>
  );
}
