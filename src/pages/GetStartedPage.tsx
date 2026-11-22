import { motion } from "framer-motion";
import { Link, useNavigate } from "react-router-dom";
import {
  Users, ChefHat, ShieldCheck, ArrowRight, UtensilsCrossed,
  ArrowLeft, CheckCircle2, Sparkles, Zap, Shield, KeyRound
} from "lucide-react";
import { ThemeToggle } from "@/components/ThemeToggle";

interface PortalCardProps {
  role: "customer" | "kitchen" | "admin";
  title: string;
  subtitle: string;
  badge: string;
  icon: typeof Users;
  gradient: string;
  borderColor: string;
  features: string[];
  loginPath: string;
  directPath: string;
  demoEmail: string;
  demoRoleName: string;
  index: number;
}

export default function GetStartedPage() {
  const navigate = useNavigate();

  const handleQuickDemo = (role: string, targetPath: string, email: string, name: string) => {
    let roles = ["customer"];
    if (role === "admin") roles = ["admin", "manager", "customer"];
    if (role === "kitchen") roles = ["kitchen", "kitchen_staff", "customer"];

    const demoUser = {
      id: `demo_${role}`,
      email,
      full_name: name,
      role,
      roles,
      created_at: new Date().toISOString(),
    };

    localStorage.setItem("token", `mock-token-${role}`);
    localStorage.setItem("user", JSON.stringify(demoUser));
    navigate(targetPath);
  };

  const portals: PortalCardProps[] = [
    {
      role: "customer",
      title: "Customer Portal",
      subtitle: "Order mouthwatering meals, reserve tables, and earn loyalty coins.",
      badge: "Dine & Enjoy",
      icon: Users,
      gradient: "from-blue-600 via-indigo-600 to-cyan-500",
      borderColor: "hover:border-blue-500/50",
      features: [
        "Curated interactive visual menu",
        "Reserve tables across patio, window, and VIP zones",
        "Live order status & takeaway tracking",
        "Café X coins & reward points redemption",
      ],
      loginPath: "/login/customer",
      directPath: "/customer",
      demoEmail: "customer@cafex.com",
      demoRoleName: "John Customer",
      index: 0,
    },
    {
      role: "kitchen",
      title: "Kitchen Portal",
      subtitle: "Live order queue, priority tickets, and real-time cooking synchronization.",
      badge: "Live Kitchen KDS",
      icon: ChefHat,
      gradient: "from-emerald-600 via-teal-600 to-green-500",
      borderColor: "hover:border-emerald-500/50",
      features: [
        "Real-time Kitchen Display System (KDS)",
        "Priority order queue with cooking countdowns",
        "Instant dish status sync across all stations",
        "Menu availability & out-of-stock toggles",
      ],
      loginPath: "/login/kitchen",
      directPath: "/kitchen",
      demoEmail: "kitchen@cafex.com",
      demoRoleName: "Head Chef Marco",
      index: 1,
    },
    {
      role: "admin",
      title: "Admin Portal",
      subtitle: "Complete operational control, real-time analytics, inventory, and staff.",
      badge: "Full Control",
      icon: ShieldCheck,
      gradient: "from-amber-600 via-orange-600 to-red-500",
      borderColor: "hover:border-amber-500/50",
      features: [
        "Executive dashboard & daily revenue metrics",
        "Table layout, zone allocation & floor plans",
        "Live orders, refunds & payment audit",
        "Inventory stock alerts & automated reordering",
      ],
      loginPath: "/login/admin",
      directPath: "/admin",
      demoEmail: "admin@cafex.com",
      demoRoleName: "Café Administrator",
      index: 2,
    },
  ];

  return (
    <div className="min-h-screen bg-background flex flex-col justify-between selection:bg-primary/20">
      {/* Top Navigation */}
      <header className="sticky top-0 z-40 bg-background/80 backdrop-blur-xl border-b border-border/40">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2.5 group">
            <div className="w-9 h-9 rounded-xl gradient-warm flex items-center justify-center shadow-md group-hover:scale-105 transition-transform">
              <UtensilsCrossed className="w-4 h-4 text-primary-foreground" />
            </div>
            <div>
              <span className="font-display font-bold text-lg text-foreground">Café X</span>
              <span className="hidden sm:inline-block ml-2 text-xs text-muted-foreground font-medium">Hotel & Dining Management</span>
            </div>
          </Link>

          <div className="flex items-center gap-3">
            <ThemeToggle />
            <Link
              to="/"
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border/60 hover:bg-muted text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              <span>Back to Home</span>
            </Link>
          </div>
        </div>
      </header>

      {/* Main Content Area */}
      <main className="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-16 w-full">
        {/* Hero Section */}
        <div className="text-center max-w-3xl mx-auto mb-12 sm:mb-16">
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0 }}
            className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-xs font-semibold text-primary mb-4"
          >
            <Sparkles className="w-3.5 h-3.5" />
            <span>Select Your Access Role</span>
          </motion.div>

          <motion.h1
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 }}
            className="text-3xl sm:text-5xl font-display font-bold text-foreground tracking-tight"
          >
            Get Started with Café X
          </motion.h1>

          <motion.p
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.2 }}
            className="mt-4 text-base sm:text-lg text-muted-foreground max-w-2xl mx-auto leading-relaxed"
          >
            Choose your portal below to sign in or create a new account. You can also explore instantly using one-click demo credentials.
          </motion.p>
        </div>

        {/* Portal Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8 mb-14">
          {portals.map((portal) => {
            const Icon = portal.icon;
            return (
              <motion.div
                key={portal.role}
                initial={{ opacity: 0, y: 30 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.15 + portal.index * 0.1 }}
                whileHover={{ y: -6 }}
                className={`glass-card-elevated rounded-2xl p-6 sm:p-7 flex flex-col justify-between border border-border/50 ${portal.borderColor} transition-all duration-300 shadow-lg hover:shadow-xl relative overflow-hidden group`}
              >
                {/* Subtle background glow */}
                <div className={`absolute top-0 right-0 w-32 h-32 bg-gradient-to-br ${portal.gradient} opacity-5 rounded-full blur-2xl group-hover:opacity-10 transition-opacity`} />

                <div>
                  {/* Top Badge and Icon */}
                  <div className="flex items-center justify-between mb-5">
                    <div className={`w-14 h-14 rounded-2xl bg-gradient-to-br ${portal.gradient} flex items-center justify-center shadow-md text-white group-hover:scale-105 transition-transform`}>
                      <Icon className="w-7 h-7" />
                    </div>
                    <span className="text-[11px] font-bold tracking-wider uppercase px-2.5 py-1 rounded-full bg-muted/80 text-foreground/80 border border-border/40">
                      {portal.badge}
                    </span>
                  </div>

                  {/* Title & Description */}
                  <h2 className="text-xl sm:text-2xl font-display font-bold text-foreground mb-2">
                    {portal.title}
                  </h2>
                  <p className="text-sm text-muted-foreground leading-relaxed mb-6">
                    {portal.subtitle}
                  </p>

                  {/* Feature Checklist */}
                  <div className="space-y-2.5 mb-8">
                    {portal.features.map((feature, i) => (
                      <div key={i} className="flex items-start gap-2.5 text-xs sm:text-sm text-foreground/80">
                        <CheckCircle2 className="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" />
                        <span>{feature}</span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Actions */}
                <div className="space-y-2.5 pt-4 border-t border-border/40">
                  <Link
                    to={portal.loginPath}
                    className={`w-full py-3 px-4 rounded-xl bg-gradient-to-r ${portal.gradient} text-white font-semibold text-sm shadow-md hover:shadow-lg flex items-center justify-center gap-2 group-hover:opacity-95 transition-all`}
                  >
                    <span>Sign In or Sign Up</span>
                    <ArrowRight className="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
                  </Link>

                  <button
                    onClick={() => handleQuickDemo(portal.role, portal.directPath, portal.demoEmail, portal.demoRoleName)}
                    className="w-full py-2.5 px-4 rounded-xl border border-border/80 hover:bg-muted/70 text-foreground font-medium text-xs flex items-center justify-center gap-2 transition-colors"
                  >
                    <Zap className="w-3.5 h-3.5 text-primary" />
                    <span>Instant Demo as {portal.demoRoleName}</span>
                  </button>
                </div>
              </motion.div>
            );
          })}
        </div>

        {/* Demo Credentials Info Callout */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.5 }}
          className="rounded-2xl border border-primary/20 bg-primary/[0.03] p-6 max-w-4xl mx-auto backdrop-blur-sm"
        >
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                <KeyRound className="w-5 h-5 text-primary" />
              </div>
              <div>
                <h3 className="font-semibold text-sm text-foreground">Standard Demo Credentials</h3>
                <p className="text-xs text-muted-foreground mt-0.5">
                  You can also sign up with your own email & password anytime.
                </p>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-3 text-xs">
              <span className="px-2.5 py-1 rounded-md bg-muted border border-border font-mono text-muted-foreground">
                admin@cafex.com / admin123
              </span>
              <span className="px-2.5 py-1 rounded-md bg-muted border border-border font-mono text-muted-foreground">
                kitchen@cafex.com / kitchen123
              </span>
              <span className="px-2.5 py-1 rounded-md bg-muted border border-border font-mono text-muted-foreground">
                customer@cafex.com / customer123
              </span>
            </div>
          </div>
        </motion.div>
      </main>

      {/* Footer */}
      <footer className="border-t border-border/40 py-6 text-center text-xs text-muted-foreground">
        <div className="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
          <p>© 2026 Café X Restaurant Management System</p>
          <div className="flex items-center gap-4">
            <Link to="/login/customer" className="hover:text-foreground transition-colors">Customer Portal</Link>
            <Link to="/login/kitchen" className="hover:text-foreground transition-colors">Kitchen Portal</Link>
            <Link to="/login/admin" className="hover:text-foreground transition-colors">Admin Portal</Link>
          </div>
        </div>
      </footer>
    </div>
  );
}
