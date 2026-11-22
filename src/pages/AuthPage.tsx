import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { useParams, Link, useNavigate, useLocation } from "react-router-dom";
import {
  ShieldCheck, ChefHat, Users, UtensilsCrossed, Eye, EyeOff,
  ArrowLeft, Mail, Lock, User, Phone, Loader2, Sparkles, KeyRound
} from "lucide-react";
import { ThemeToggle } from "@/components/ThemeToggle";
import { SmallScene3D } from "@/components/Scene3D";
import { api } from "@/lib/api";

type RoleKey = "admin" | "customer" | "kitchen";

const roleConfig: Record<RoleKey, {
  icon: typeof ShieldCheck;
  title: string;
  subtitle: string;
  color: string;
  redirectTo: string;
  demoEmail: string;
  demoPass: string;
}> = {
  customer: {
    icon: Users,
    title: "Customer Portal",
    subtitle: "Order food, reserve tables & track your meals",
    color: "from-blue-600 to-cyan-600",
    redirectTo: "/customer",
    demoEmail: "customer@cafex.com",
    demoPass: "customer123",
  },
  kitchen: {
    icon: ChefHat,
    title: "Kitchen Portal",
    subtitle: "Manage orders, tickets & preparation queue",
    color: "from-emerald-600 to-teal-600",
    redirectTo: "/kitchen",
    demoEmail: "kitchen@cafex.com",
    demoPass: "kitchen123",
  },
  admin: {
    icon: ShieldCheck,
    title: "Admin Portal",
    subtitle: "Full system management, staff, & analytics",
    color: "from-amber-600 to-orange-600",
    redirectTo: "/admin",
    demoEmail: "admin@cafex.com",
    demoPass: "admin123",
  },
};

export default function AuthPage() {
  const { role } = useParams<{ role?: string }>();
  const location = useLocation();
  const navigate = useNavigate();

  // Determine active role based on url or fallback to customer
  const initialRole: RoleKey = (role && role in roleConfig)
    ? (role as RoleKey)
    : "customer";

  const [activeRole, setActiveRole] = useState<RoleKey>(initialRole);
  const [isSignUp, setIsSignUp] = useState<boolean>(() => {
    return location.pathname.includes("signup") || location.search.includes("signup=true");
  });
  const [showPassword, setShowPassword] = useState(false);
  const [formData, setFormData] = useState({ name: "", email: "", phone: "", password: "" });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  // Sync role if url param changes
  useEffect(() => {
    if (role && role in roleConfig) {
      setActiveRole(role as RoleKey);
    }
  }, [role]);

  const config = roleConfig[activeRole];
  const Icon = config.icon;

  const handleRoleChange = (newRole: RoleKey) => {
    setActiveRole(newRole);
    setError("");
    setSuccess("");
    // Optionally update URL smoothly
    if (role) {
      navigate(`/login/${newRole}`, { replace: true });
    }
  };

  const handleFillDemo = () => {
    setFormData((prev) => ({
      ...prev,
      email: config.demoEmail,
      password: config.demoPass,
    }));
    setError("");
    setSuccess(`Filled credentials for ${config.title}`);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setSuccess("");
    setIsLoading(true);

    try {
      if (isSignUp) {
        const response = await api.post("/auth/signup", {
          full_name: formData.name,
          email: formData.email,
          password: formData.password,
          role: activeRole,
          phone: formData.phone,
        });

        const token = response?.token || response?.session?.access_token;
        if (token) {
          const userObj = {
            ...response.user,
            role: activeRole,
            roles: response.roles || response.user?.roles || [activeRole, "customer"],
          };
          localStorage.setItem("token", token);
          localStorage.setItem("user", JSON.stringify(userObj));
          navigate(config.redirectTo);
        } else {
          setIsSignUp(false);
          setFormData((prev) => ({ ...prev, password: "" }));
          setSuccess("Account created successfully! Please sign in.");
        }
      } else {
        // Login flow
        const response = await api.post("/auth/login", {
          email: formData.email,
          password: formData.password,
        });

        const userRoles: string[] = response?.roles || response?.user?.roles || [];
        const requestedRole = activeRole;

        let hasAccess = false;
        if (requestedRole === "admin") {
          hasAccess = userRoles.includes("admin") || userRoles.includes("manager");
        } else if (requestedRole === "kitchen") {
          hasAccess = userRoles.includes("kitchen") || userRoles.includes("kitchen_staff") || userRoles.includes("admin") || userRoles.includes("manager");
        } else {
          hasAccess = true; // Customers can always access customer portal
        }

        if (!hasAccess) {
          setError(`Access denied. This account does not have ${requestedRole} privileges. You can switch portal tabs above or register a new ${requestedRole} account.`);
          setIsLoading(false);
          return;
        }

        const token = response?.session?.access_token || response?.token;
        if (token) {
          localStorage.setItem("token", token);
          localStorage.setItem("user", JSON.stringify({
            ...response.user,
            role: requestedRole,
            roles: userRoles.length > 0 ? userRoles : [requestedRole, "customer"],
          }));
          navigate(config.redirectTo);
        } else {
          setError("Login failed: No access token returned.");
        }
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : "An unexpected error occurred. Please check your credentials.";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex bg-background selection:bg-primary/20">
      {/* Left panel - 3D scene (hidden on mobile) */}
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden gradient-hero items-center justify-center">
        <div className="absolute inset-0 opacity-30">
          <SmallScene3D />
        </div>
        <div className="relative z-10 text-center px-12 max-w-lg">
          <motion.div
            key={activeRole}
            initial={{ scale: 0.8, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            transition={{ duration: 0.5 }}
            className={`w-24 h-24 rounded-3xl bg-gradient-to-br ${config.color} flex items-center justify-center mx-auto mb-8 shadow-2xl text-white`}
          >
            <Icon className="w-12 h-12" />
          </motion.div>
          <motion.h2
            key={`title-${activeRole}`}
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.15 }}
            className="text-4xl font-display font-bold text-white mb-4"
          >
            {config.title}
          </motion.h2>
          <motion.p
            key={`sub-${activeRole}`}
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.25 }}
            className="text-lg text-white/80 leading-relaxed"
          >
            {config.subtitle}
          </motion.p>
        </div>
      </div>

      {/* Right panel - form */}
      <div className="flex-1 flex flex-col justify-between">
        {/* Top Header */}
        <div className="flex items-center justify-between p-4 sm:p-6 border-b border-border/30">
          <div className="flex items-center gap-2">
            <Link to="/get-started" className="flex items-center gap-1.5 text-xs sm:text-sm font-medium text-muted-foreground hover:text-foreground transition-colors mr-2">
              <ArrowLeft className="w-4 h-4" />
              <span>Get Started</span>
            </Link>
            <span className="text-muted-foreground/40 hidden sm:inline">|</span>
            <Link to="/" className="text-xs sm:text-sm text-muted-foreground hover:text-foreground transition-colors hidden sm:inline">
              Home
            </Link>
          </div>
          <div className="flex items-center gap-3">
            <ThemeToggle />
            <Link to="/" className="flex items-center gap-2">
              <div className="w-8 h-8 rounded-lg gradient-warm flex items-center justify-center shadow-sm">
                <UtensilsCrossed className="w-4 h-4 text-primary-foreground" />
              </div>
              <span className="font-display font-bold text-foreground hidden sm:block">Café X</span>
            </Link>
          </div>
        </div>

        {/* Form Container */}
        <div className="flex-1 flex items-center justify-center p-4 sm:p-8">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="w-full max-w-md py-4"
          >
            {/* Role Switcher Tabs */}
            <div className="mb-6 p-1 rounded-xl bg-muted/80 border border-border/50 grid grid-cols-3 gap-1">
              {(["customer", "kitchen", "admin"] as RoleKey[]).map((r) => {
                const isSelected = activeRole === r;
                const TabIcon = roleConfig[r].icon;
                return (
                  <button
                    key={r}
                    type="button"
                    onClick={() => handleRoleChange(r)}
                    className={`flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-lg text-xs font-semibold capitalize transition-all ${
                      isSelected
                        ? "bg-background text-foreground shadow-sm border border-border/40"
                        : "text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    <TabIcon className="w-3.5 h-3.5" />
                    <span>{r}</span>
                  </button>
                );
              })}
            </div>

            {/* Mobile role icon & title */}
            <div className="text-center mb-6">
              <div className="lg:hidden flex justify-center mb-4">
                <div className={`w-14 h-14 rounded-2xl bg-gradient-to-br ${config.color} flex items-center justify-center shadow-lg text-white`}>
                  <Icon className="w-7 h-7" />
                </div>
              </div>

              <h1 className="text-2xl sm:text-3xl font-display font-bold text-foreground">
                {isSignUp ? `Create ${activeRole === "admin" ? "Admin" : activeRole === "kitchen" ? "Kitchen" : "Customer"} Account` : `Sign In to ${config.title}`}
              </h1>
              <p className="text-muted-foreground mt-1.5 text-xs sm:text-sm">
                {isSignUp
                  ? `Register to access the ${config.title}`
                  : `Enter your credentials or use the demo login below`}
              </p>
            </div>

            {/* Feedback notifications */}
            {success && (
              <div className="mb-4 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs sm:text-sm text-center">
                {success}
              </div>
            )}

            {error && (
              <div className="mb-4 p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs sm:text-sm text-center leading-relaxed">
                {error}
              </div>
            )}

            {/* Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              <AnimatePresence mode="wait">
                {isSignUp && (
                  <motion.div
                    key="name"
                    initial={{ opacity: 0, height: 0 }}
                    animate={{ opacity: 1, height: "auto" }}
                    exit={{ opacity: 0, height: 0 }}
                  >
                    <label className="text-xs font-semibold text-foreground mb-1 block">Full Name</label>
                    <div className="relative">
                      <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                      <input
                        type="text"
                        placeholder="e.g. Alex Morgan"
                        required
                        value={formData.name}
                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        className="w-full h-11 pl-10 pr-4 rounded-xl border border-input bg-background text-foreground text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all"
                      />
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>

              <div>
                <label className="text-xs font-semibold text-foreground mb-1 block">Email Address</label>
                <div className="relative">
                  <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                  <input
                    type="email"
                    placeholder="you@example.com"
                    required
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    className="w-full h-11 pl-10 pr-4 rounded-xl border border-input bg-background text-foreground text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all"
                  />
                </div>
              </div>

              <AnimatePresence mode="wait">
                {isSignUp && (
                  <motion.div
                    key="phone"
                    initial={{ opacity: 0, height: 0 }}
                    animate={{ opacity: 1, height: "auto" }}
                    exit={{ opacity: 0, height: 0 }}
                  >
                    <label className="text-xs font-semibold text-foreground mb-1 block">Phone Number (optional)</label>
                    <div className="relative">
                      <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                      <input
                        type="tel"
                        placeholder="+1 (555) 000-0000"
                        value={formData.phone}
                        onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                        className="w-full h-11 pl-10 pr-4 rounded-xl border border-input bg-background text-foreground text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all"
                      />
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>

              <div>
                <label className="text-xs font-semibold text-foreground mb-1 block">Password</label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                  <input
                    type={showPassword ? "text" : "password"}
                    placeholder="At least 6 characters"
                    required
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    className="w-full h-11 pl-10 pr-11 rounded-xl border border-input bg-background text-foreground text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none transition-all"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              {/* Submit button */}
              <motion.button
                type="submit"
                whileHover={{ scale: 1.01 }}
                whileTap={{ scale: 0.99 }}
                disabled={isLoading}
                className={`w-full h-11 rounded-xl bg-gradient-to-r ${config.color} text-white font-semibold text-sm shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed`}
              >
                {isLoading && <Loader2 className="w-4 h-4 animate-spin" />}
                {isSignUp ? `Create ${config.title} Account` : `Sign In to ${config.title}`}
              </motion.button>
            </form>

            {/* Quick Demo Autofill Button */}
            {!isSignUp && (
              <div className="mt-3">
                <button
                  type="button"
                  onClick={handleFillDemo}
                  className="w-full py-2 px-3 rounded-xl border border-dashed border-border/80 hover:border-primary/50 text-xs font-medium text-muted-foreground hover:text-foreground bg-muted/30 hover:bg-muted/60 transition-colors flex items-center justify-center gap-1.5"
                >
                  <KeyRound className="w-3.5 h-3.5 text-primary" />
                  <span>Use Demo Credentials ({config.demoEmail})</span>
                </button>
              </div>
            )}

            {/* Switch between Sign In / Sign Up */}
            <p className="text-center text-xs sm:text-sm text-muted-foreground mt-5">
              {isSignUp ? "Already have an account?" : "Don't have an account?"}{" "}
              <button
                type="button"
                onClick={() => {
                  setIsSignUp(!isSignUp);
                  setFormData({ name: "", email: "", phone: "", password: "" });
                  setError("");
                  setSuccess("");
                }}
                className="text-primary font-semibold hover:underline cursor-pointer"
              >
                {isSignUp ? "Sign In" : "Sign Up"}
              </button>
            </p>

            {/* Other Portals / Get Started Shortcut */}
            <div className="mt-6 pt-5 border-t border-border/50 text-center">
              <Link
                to="/get-started"
                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-primary transition-colors font-medium"
              >
                <Sparkles className="w-3.5 h-3.5" />
                <span>Explore all portals on the Get Started page</span>
              </Link>
            </div>
          </motion.div>
        </div>

        {/* Footer */}
        <div className="p-4 text-center text-xs text-muted-foreground border-t border-border/30">
          © 2026 Café X Restaurant Management System
        </div>
      </div>
    </div>
  );
}
