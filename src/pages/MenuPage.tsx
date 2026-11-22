import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Plus, Search, Star, Clock, Flame, Leaf, Edit2, Trash2, Eye, EyeOff, RefreshCw, AlertCircle } from "lucide-react";
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

interface MenuItemModel {
  id: number;
  name: string;
  category?: { id: number; name: string };
  price: number | string;
  image_url?: string | null;
  description?: string | null;
  prep_time_minutes?: number | null;
  is_available: boolean;
  is_featured?: boolean;
  is_spicy?: boolean;
  is_vegetarian?: boolean;
  calories?: number | null;
}

interface CategoryOption {
  id: number;
  name: string;
  slug: string;
}

export default function MenuPage() {
  const [items, setItems] = useState<MenuItemModel[]>([]);
  const [categories, setCategories] = useState<string[]>(["All"]);
  const [activeCategory, setActiveCategory] = useState("All");
  const [search, setSearch] = useState("");
  const [isLoading, setIsLoading] = useState(true);

  // Add Item Modal
  const [isAddOpen, setIsAddOpen] = useState(false);
  const [addName, setAddName] = useState("");
  const [addPrice, setAddPrice] = useState("");
  const [addCategory, setAddCategory] = useState("Mains & Steaks");
  const [addDesc, setAddDesc] = useState("");
  const [addImageUrl, setAddImageUrl] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const fetchMenuData = async () => {
    setIsLoading(true);
    try {
      const [menuRes, catRes] = await Promise.allSettled([
        api.getMenu(),
        api.getMenuCategories(),
      ]);

      if (menuRes.status === "fulfilled" && Array.isArray(menuRes.value)) {
        setItems(menuRes.value);
      }

      if (catRes.status === "fulfilled" && Array.isArray(catRes.value)) {
        const catNames = ["All", ...new Set(catRes.value.map((c: any) => c.name))];
        setCategories(catNames as string[]);
      }
    } catch (err) {
      console.error("Failed to load menu data:", err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchMenuData();
  }, []);

  const handleToggleAvailability = async (item: MenuItemModel) => {
    try {
      await api.toggleMenuItemAvailability(item.id);
      setItems((prev) =>
        prev.map((i) => (i.id === item.id ? { ...i, is_available: !i.is_available } : i))
      );
      toast({
        title: item.is_available ? "Item marked unavailable" : "Item marked available",
        description: `${item.name} is now ${item.is_available ? "hidden from customer ordering" : "live for orders"}.`,
      });
    } catch (err: any) {
      toast({
        title: "Update failed",
        description: err.message || "Could not toggle availability.",
        variant: "destructive",
      });
    }
  };

  const handleAddItem = async () => {
    if (!addName.trim() || !addPrice) {
      toast({
        title: "Missing fields",
        description: "Please provide a name and price for the dish.",
        variant: "destructive",
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = {
        name: addName.trim(),
        price: parseFloat(addPrice),
        category: addCategory,
        description: addDesc.trim() || undefined,
        image_url: addImageUrl.trim() || undefined,
        is_available: true,
      };

      await api.post('/menu', payload);
      toast({
        title: "Dish added successfully",
        description: `${addName} has been published to the menu.`,
      });
      setIsAddOpen(false);
      setAddName("");
      setAddPrice("");
      setAddDesc("");
      setAddImageUrl("");
      fetchMenuData();
    } catch (err: any) {
      toast({
        title: "Failed to add dish",
        description: err.message || "An unexpected error occurred.",
        variant: "destructive",
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  const filtered = items.filter((item) => {
    const itemCat = item.category?.name || "Kitchen";
    const matchCat = activeCategory === "All" || itemCat.toLowerCase() === activeCategory.toLowerCase();
    const matchSearch =
      item.name.toLowerCase().includes(search.toLowerCase()) ||
      (item.description && item.description.toLowerCase().includes(search.toLowerCase()));
    return matchCat && matchSearch;
  });

  return (
    <AppLayout>
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <PageHeader
          title="Menu Management"
          subtitle="Publish culinary specialties, toggle 86/sold-out statuses, and manage pricing"
        />
        <div className="flex items-center gap-2 self-start sm:self-center">
          <Button
            variant="outline"
            size="sm"
            onClick={fetchMenuData}
            disabled={isLoading}
            className="gap-2"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? "animate-spin" : ""}`} />
            Refresh
          </Button>
          <Button
            onClick={() => setIsAddOpen(true)}
            className="gradient-warm text-primary-foreground font-semibold gap-2 shadow-lg"
          >
            <Plus className="w-4 h-4" /> Add Dish
          </Button>
        </div>
      </div>

      {/* Search & Categories */}
      <div className="mb-6 space-y-4">
        <div className="relative max-w-md">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search menu items, ingredients..."
            className="pl-10 text-xs rounded-xl"
          />
        </div>
        <div className="flex gap-2 overflow-x-auto pb-1">
          {categories.map((cat) => (
            <button
              key={cat}
              onClick={() => setActiveCategory(cat)}
              className={`px-3.5 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition-all ${
                activeCategory === cat
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "bg-card border border-border text-foreground hover:bg-muted"
              }`}
            >
              {cat}
            </button>
          ))}
        </div>
      </div>

      {/* Dishes Grid */}
      {filtered.length === 0 ? (
        <div className="glass-card p-12 text-center rounded-2xl border border-border/50">
          <AlertCircle className="w-8 h-8 mx-auto mb-2 text-muted-foreground/40" />
          <p className="text-sm font-semibold text-foreground">No menu items found</p>
          <p className="text-xs text-muted-foreground mt-1">
            {search ? "Try clearing your search query." : "Tap 'Add Dish' to create items."}
          </p>
        </div>
      ) : (
        <motion.div layout className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          <AnimatePresence mode="popLayout">
            {filtered.map((item) => (
              <motion.div
                key={item.id}
                layout
                initial={{ opacity: 0, scale: 0.95 }}
                animate={{ opacity: 1, scale: 1 }}
                exit={{ opacity: 0, scale: 0.95 }}
                whileHover={{ y: -4, transition: { duration: 0.2 } }}
                className={`glass-card-elevated rounded-2xl overflow-hidden group border border-border/50 transition-all ${
                  !item.is_available ? "opacity-60" : ""
                }`}
              >
                {/* Photo Header */}
                <div className="h-44 bg-muted relative overflow-hidden">
                  {item.image_url ? (
                    <img
                      src={item.image_url}
                      alt={item.name}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                    />
                  ) : (
                    <div className="w-full h-full flex items-center justify-center text-4xl bg-muted/60">
                      🍽️
                    </div>
                  )}

                  {/* Availability badge */}
                  {!item.is_available && (
                    <div className="absolute inset-0 bg-background/70 backdrop-blur-[2px] flex items-center justify-center">
                      <span className="text-xs font-bold text-destructive bg-destructive/15 border border-destructive/30 px-3 py-1 rounded-full uppercase tracking-wider">
                        Unavailable (86)
                      </span>
                    </div>
                  )}

                  {/* Actions overlay */}
                  <div className="absolute top-2.5 right-2.5 flex gap-1.5 opacity-90 group-hover:opacity-100 transition-opacity">
                    <button
                      onClick={() => handleToggleAvailability(item)}
                      title={item.is_available ? "Mark as 86 / Sold Out" : "Mark as Available"}
                      className="w-8 h-8 rounded-xl bg-background/90 backdrop-blur border border-border shadow-md flex items-center justify-center hover:bg-muted text-foreground transition-all"
                    >
                      {item.is_available ? (
                        <Eye className="w-4 h-4 text-emerald-600" />
                      ) : (
                        <EyeOff className="w-4 h-4 text-destructive" />
                      )}
                    </button>
                  </div>
                </div>

                <div className="p-4">
                  <div className="flex items-start justify-between gap-2 mb-1">
                    <h3 className="font-display font-bold text-foreground text-sm line-clamp-1">
                      {item.name}
                    </h3>
                    <span className="text-primary font-bold font-mono text-sm shrink-0">
                      ${Number(item.price).toFixed(2)}
                    </span>
                  </div>

                  <p className="text-xs text-muted-foreground mb-3 line-clamp-2 h-8">
                    {item.description || "Authentic chef specialty prepared with fresh seasonal ingredients."}
                  </p>

                  <div className="flex items-center justify-between text-[11px] text-muted-foreground pt-2 border-t border-border/40">
                    <div className="flex items-center gap-1">
                      <Clock className="w-3 h-3" />
                      <span>{item.prep_time_minutes || 15}m</span>
                    </div>
                    <span className="bg-muted px-2 py-0.5 rounded-full text-[10px] font-medium text-foreground">
                      {item.category?.name || "Kitchen"}
                    </span>
                  </div>
                </div>
              </motion.div>
            ))}
          </AnimatePresence>
        </motion.div>
      )}

      {/* Add Dish Dialog */}
      <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Add New Menu Dish</DialogTitle>
            <DialogDescription>
              Create a new culinary offering for online and QR table menus.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2 text-xs">
            <div>
              <label className="font-semibold block mb-1">Dish Name *</label>
              <Input
                value={addName}
                onChange={(e) => setAddName(e.target.value)}
                placeholder="e.g. Truffle Burrata Salad"
                className="text-xs"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="font-semibold block mb-1">Price ($) *</label>
                <Input
                  type="number"
                  step="0.5"
                  value={addPrice}
                  onChange={(e) => setAddPrice(e.target.value)}
                  placeholder="24.50"
                  className="text-xs"
                />
              </div>
              <div>
                <label className="font-semibold block mb-1">Category</label>
                <select
                  value={addCategory}
                  onChange={(e) => setAddCategory(e.target.value)}
                  className="w-full text-xs rounded-xl border border-border bg-background p-2 h-9"
                >
                  <option value="Mains & Steaks">Mains & Steaks</option>
                  <option value="Pizza & Pasta">Pizza & Pasta</option>
                  <option value="Desserts">Desserts</option>
                  <option value="Drinks">Drinks</option>
                  <option value="Appetizers">Appetizers</option>
                </select>
              </div>
            </div>

            <div>
              <label className="font-semibold block mb-1">Description</label>
              <Input
                value={addDesc}
                onChange={(e) => setAddDesc(e.target.value)}
                placeholder="e.g. Creamy burrata, heirloom tomatoes, balsamic reduction"
                className="text-xs"
              />
            </div>

            <div>
              <label className="font-semibold block mb-1">Photo Image URL</label>
              <Input
                value={addImageUrl}
                onChange={(e) => setAddImageUrl(e.target.value)}
                placeholder="https://images.unsplash.com/..."
                className="text-xs"
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setIsAddOpen(false)}>
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleAddItem}
              disabled={isSubmitting || !addName.trim() || !addPrice}
              className="gradient-warm text-primary-foreground font-semibold"
            >
              {isSubmitting ? "Publishing..." : "Publish Dish"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
