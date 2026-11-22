import React, { useEffect, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { motion } from "framer-motion";
import { 
  UtensilsCrossed, 
  Receipt, 
  BellRing, 
  CreditCard, 
  Sparkles, 
  Users, 
  Clock, 
  CheckCircle2, 
  AlertCircle, 
  Coffee,
  ChevronRight,
  ShieldCheck,
  User,
  Phone
} from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface TableInfo {
  id: number;
  table_number: string;
  name: string;
  capacity: number;
  zone: string;
  status: string;
  qr_token: string;
}

interface BranchInfo {
  id: number;
  name: string;
  currency: string;
  tax_rate: string;
  service_charge: string;
}

interface SessionInfo {
  id: number;
  session_token: string;
  status: string;
  opened_at: string;
  total: string | null;
  guests_count: number;
}

export default function QrLandingPage() {
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [table, setTable] = useState<TableInfo | null>(null);
  const [branch, setBranch] = useState<BranchInfo | null>(null);
  const [session, setSession] = useState<SessionInfo | null>(null);
  const [guests, setGuests] = useState<any[]>([]);

  // Guest Identification
  const [guestName, setGuestName] = useState(() => {
    return localStorage.getItem("cafex_guest_name") || "";
  });
  const [guestPhone, setGuestPhone] = useState(() => {
    return localStorage.getItem("cafex_guest_phone") || "";
  });

  // Call Waiter Modal state
  const [waiterModalOpen, setWaiterModalOpen] = useState(false);
  const [waiterReason, setWaiterReason] = useState("assistance");
  const [callingWaiter, setCallingWaiter] = useState(false);

  // Request Bill Modal state
  const [billModalOpen, setBillModalOpen] = useState(false);
  const [requestingBill, setRequestingBill] = useState(false);

  useEffect(() => {
    if (!token) {
      setError("No table QR token provided.");
      setLoading(false);
      return;
    }

    const loadTableData = async () => {
      try {
        setLoading(true);
        setError(null);
        const data = await api.scanTable(token);
        setTable(data.table);
        setBranch(data.branch);
        setSession(data.session);
        setGuests(data.guests || []);

        // Store active session in session storage for frictionless ordering
        sessionStorage.setItem("cafex_active_qr_token", token);
        sessionStorage.setItem("cafex_active_table_number", data.table.table_number);
        sessionStorage.setItem("cafex_active_table_id", String(data.table.id));
        if (data.session) {
          sessionStorage.setItem("cafex_active_session_id", String(data.session.id));
        }
      } catch (err: any) {
        console.error("QR Scan failed:", err);
        setError(err.message || "Invalid or expired QR code.");
      } finally {
        setLoading(false);
      }
    };

    loadTableData();
  }, [token]);

  const handleSaveGuestProfile = () => {
    if (guestName.trim()) {
      localStorage.setItem("cafex_guest_name", guestName.trim());
      if (guestPhone.trim()) {
        localStorage.setItem("cafex_guest_phone", guestPhone.trim());
      }
      toast({
        title: "Name Saved",
        description: `Your orders at ${table?.table_number || "this table"} will be labeled as "${guestName.trim()}".`,
      });
    }
  };

  const handleCallWaiter = async () => {
    if (!token) return;
    try {
      setCallingWaiter(true);
      await api.callWaiter(token, {
        guest_name: guestName.trim() || "Guest",
        reason: waiterReason,
      });
      toast({
        title: "Waiter Notified",
        description: `A floor team member has been alerted for ${table?.table_number}.`,
      });
      setWaiterModalOpen(false);
    } catch (err: any) {
      toast({
        title: "Request Failed",
        description: err.message || "Could not call waiter. Please try again.",
        variant: "destructive",
      });
    } finally {
      setCallingWaiter(false);
    }
  };

  const handleRequestBill = async () => {
    if (!token) return;
    try {
      setRequestingBill(true);
      await api.requestBill(token, {
        guest_name: guestName.trim() || "Guest",
      });
      toast({
        title: "Bill Requested",
        description: "Your waiter has received the bill request and will bring it over shortly.",
      });
      setBillModalOpen(false);
    } catch (err: any) {
      toast({
        title: "Request Failed",
        description: err.message || "Could not request bill.",
        variant: "destructive",
      });
    } finally {
      setRequestingBill(false);
    }
  };

  const handleNavigateToMenu = async () => {
    handleSaveGuestProfile();
    try {
      if (token) {
        const joinRes: any = await api.joinTableSession(
          token,
          guestName.trim() || "Guest",
          guestPhone.trim() || undefined
        );
        if (joinRes?.guest_secret) {
          localStorage.setItem("cafex_guest_secret", joinRes.guest_secret);
        }
      }
    } catch (e) {
      console.warn("Session join warning:", e);
    }
    navigate(`/customer/menu?token=${token}&table=${encodeURIComponent(table?.table_number || "")}`);
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-stone-900 text-stone-100 flex flex-col items-center justify-center p-6">
        <motion.div
          animate={{ rotate: 360 }}
          transition={{ repeat: Infinity, duration: 1.2, ease: "linear" }}
          className="w-12 h-12 border-4 border-amber-500 border-t-transparent rounded-full mb-4"
        />
        <p className="text-stone-400 text-lg">Opening your table session...</p>
      </div>
    );
  }

  if (error || !table) {
    const isPaused = error?.toLowerCase().includes("pause");
    return (
      <div className="min-h-screen bg-stone-900 text-stone-100 flex flex-col items-center justify-center p-6 text-center">
        <div className={`w-16 h-16 ${isPaused ? "bg-amber-950/60 border-amber-800 text-amber-400" : "bg-red-950/60 border-red-800 text-red-400"} border rounded-2xl flex items-center justify-center mb-4`}>
          {isPaused ? <Coffee className="w-8 h-8" /> : <AlertCircle className="w-8 h-8" />}
        </div>
        <h1 className="text-2xl font-bold mb-2">
          {isPaused ? "QR Ordering Paused" : "QR Code Not Recognized"}
        </h1>
        <p className="text-stone-400 max-w-sm mb-6 text-sm">
          {error || "We couldn't connect this QR code to an active table. Please ask your server for assistance."}
        </p>
        <div className="flex gap-3">
          <Link
            to="/"
            className="px-6 py-2.5 bg-stone-800 hover:bg-stone-750 text-stone-300 font-semibold rounded-xl text-sm transition-colors"
          >
            Return to Home
          </Link>
          {isPaused && (
            <button
              onClick={() => window.location.reload()}
              className="px-6 py-2.5 bg-amber-500 hover:bg-amber-600 text-stone-950 font-bold rounded-xl text-sm transition-colors"
            >
              Check Again
            </button>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-stone-950 text-stone-100 flex flex-col">
      {/* Top Header */}
      <header className="border-b border-stone-800/80 bg-stone-900/60 backdrop-blur-md sticky top-0 z-20 px-4 py-3.5 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400 font-black text-lg">
            X
          </div>
          <div>
            <h2 className="font-bold text-stone-100 leading-tight">Café X</h2>
            <p className="text-xs text-stone-400">{branch?.name || "Artisan Kitchen"}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/15 border border-amber-500/30 text-amber-300">
            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            {table.table_number}
          </span>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 max-w-md w-full mx-auto p-4 flex flex-col gap-5">
        {/* Welcome Hero Banner */}
        <motion.div
          initial={{ opacity: 0, y: 15 }}
          animate={{ opacity: 1, y: 0 }}
          className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-amber-950/40 via-stone-900 to-stone-900 border border-amber-900/40 p-6 shadow-xl"
        >
          <div className="absolute top-0 right-0 -mr-6 -mt-6 w-32 h-32 bg-amber-500/10 rounded-full blur-2xl pointer-events-none" />
          
          <div className="flex items-center justify-between mb-3">
            <span className="text-xs uppercase tracking-wider text-amber-400/90 font-bold flex items-center gap-1.5">
              <Sparkles className="w-3.5 h-3.5" /> Dine-In Session
            </span>
            <span className="text-xs text-stone-400">Zone: {table.zone}</span>
          </div>

          <h1 className="text-2xl font-black text-stone-100 mb-1">
            Welcome to {table.table_number}
          </h1>
          <p className="text-stone-300 text-sm mb-4">
            Order directly from your phone. All guests at this table share one running tab. No app or registration required!
          </p>

          {/* Table Session Details */}
          <div className="bg-stone-950/60 rounded-2xl border border-stone-800/80 p-3.5 flex items-center justify-between text-xs">
            <div className="flex items-center gap-2.5">
              <div className="w-7 h-7 rounded-lg bg-stone-800 flex items-center justify-center text-amber-400">
                <Users className="w-4 h-4" />
              </div>
              <div>
                <p className="text-stone-400 font-medium">Shared Table Tab</p>
                <p className="font-semibold text-stone-200">
                  {session?.total ? `Total: ${branch?.currency === 'EUR' ? '€' : '$'}${session.total}` : 'Tab is currently empty'}
                </p>
              </div>
            </div>
            <Link
              to={`/t/${token}/tab`}
              className="text-amber-400 hover:text-amber-300 font-semibold flex items-center gap-1"
            >
              View Tab <ChevronRight className="w-3.5 h-3.5" />
            </Link>
          </div>
        </motion.div>

        {/* Guest Name & Optional Rewards Bar */}
        <div className="bg-stone-900/70 border border-stone-800 rounded-2xl p-4 shadow-sm">
          <div className="flex items-center justify-between mb-2">
            <label className="text-xs font-semibold text-stone-300 flex items-center gap-1.5">
              <User className="w-3.5 h-3.5 text-amber-400" /> Your Name (for order tickets)
            </label>
            <Link to="/login" className="text-xs text-amber-400 hover:underline">
              Log in for Coins
            </Link>
          </div>
          <div className="flex gap-2">
            <input
              type="text"
              value={guestName}
              onChange={(e) => setGuestName(e.target.value)}
              placeholder="e.g. Abel or Sara"
              className="flex-1 bg-stone-950 border border-stone-700/80 rounded-xl px-3 py-2 text-sm text-stone-100 placeholder:text-stone-500 focus:outline-none focus:border-amber-500"
            />
            <button
              onClick={handleSaveGuestProfile}
              className="px-3.5 py-2 bg-stone-800 hover:bg-stone-700 border border-stone-700 text-stone-200 text-xs font-medium rounded-xl transition-colors"
            >
              Save
            </button>
          </div>
        </div>

        {/* Primary Action Buttons */}
        <div className="grid grid-cols-1 gap-3">
          {/* View Menu & Order */}
          <button
            onClick={handleNavigateToMenu}
            className="w-full group bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-stone-950 font-bold p-4 rounded-2xl shadow-lg flex items-center justify-between transition-all transform active:scale-[0.99]"
          >
            <div className="flex items-center gap-3.5">
              <div className="w-10 h-10 rounded-xl bg-stone-950/15 flex items-center justify-center text-stone-950">
                <UtensilsCrossed className="w-5 h-5" />
              </div>
              <div className="text-left">
                <p className="text-base font-black leading-tight">View Menu & Order</p>
                <p className="text-xs font-medium text-stone-900/80">Explore dishes, customize & order</p>
              </div>
            </div>
            <ChevronRight className="w-5 h-5 text-stone-950 group-hover:translate-x-1 transition-transform" />
          </button>

          {/* My Table's Tab / Orders */}
          <Link
            to={`/t/${token}/tab`}
            className="w-full bg-stone-900 hover:bg-stone-850 border border-stone-800 hover:border-stone-700 p-4 rounded-2xl flex items-center justify-between transition-colors"
          >
            <div className="flex items-center gap-3.5">
              <div className="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                <Receipt className="w-5 h-5" />
              </div>
              <div className="text-left">
                <p className="text-sm font-bold text-stone-100">Table Tab & Orders</p>
                <p className="text-xs text-stone-400">Live kitchen tracker & bill breakdown</p>
              </div>
            </div>
            <div className="text-right flex items-center gap-2">
              <span className="text-xs font-bold text-amber-400">
                {session?.total ? `${branch?.currency === 'EUR' ? '€' : '$'}${session.total}` : 'Active'}
              </span>
              <ChevronRight className="w-4 h-4 text-stone-500" />
            </div>
          </Link>

          {/* Quick Service Grid */}
          <div className="grid grid-cols-2 gap-3 pt-1">
            {/* Call Waiter */}
            <button
              onClick={() => setWaiterModalOpen(true)}
              className="bg-stone-900 hover:bg-stone-850 border border-stone-800 p-3.5 rounded-2xl flex flex-col items-center justify-center text-center gap-2 transition-colors"
            >
              <div className="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                <BellRing className="w-4 h-4" />
              </div>
              <span className="text-xs font-bold text-stone-200">Call Waiter</span>
              <span className="text-[10px] text-stone-400">Water, cutlery, help</span>
            </button>

            {/* Request Bill */}
            <button
              onClick={() => setBillModalOpen(true)}
              className="bg-stone-900 hover:bg-stone-850 border border-stone-800 p-3.5 rounded-2xl flex flex-col items-center justify-center text-center gap-2 transition-colors"
            >
              <div className="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                <CreditCard className="w-4 h-4" />
              </div>
              <span className="text-xs font-bold text-stone-200">Request Bill</span>
              <span className="text-[10px] text-stone-400">Pay at table or split</span>
            </button>
          </div>
        </div>

        {/* Today's Special Deal Banner */}
        <div className="bg-gradient-to-r from-amber-500/10 to-orange-500/10 border border-amber-500/20 rounded-2xl p-4 flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-amber-500/20 flex items-center justify-center text-amber-400 flex-shrink-0">
            <Sparkles className="w-5 h-5" />
          </div>
          <div className="text-xs">
            <p className="font-bold text-amber-300">Today's Special</p>
            <p className="text-stone-300">
              Order any Main Course & receive 20% off all artisan desserts!
            </p>
          </div>
        </div>
      </main>

      {/* Call Waiter Modal */}
      {waiterModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <motion.div
            initial={{ scale: 0.95, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            className="bg-stone-900 border border-stone-800 rounded-3xl max-w-sm w-full p-5 shadow-2xl"
          >
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-400">
                <BellRing className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-bold text-stone-100">Call Waiter</h3>
                <p className="text-xs text-stone-400">For {table.table_number}</p>
              </div>
            </div>

            <p className="text-xs text-stone-300 mb-3">Select what you need:</p>
            <div className="grid grid-cols-2 gap-2 mb-4">
              {[
                { id: "water", label: "Water Refill" },
                { id: "cutlery", label: "Cutlery / Napkins" },
                { id: "assistance", label: "Table Assistance" },
                { id: "order_help", label: "Menu Questions" },
              ].map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => setWaiterReason(item.id)}
                  className={`px-3 py-2.5 rounded-xl text-xs font-semibold border transition-all text-left ${
                    waiterReason === item.id
                      ? "bg-blue-500/20 border-blue-500 text-blue-300"
                      : "bg-stone-950 border-stone-800 text-stone-300 hover:border-stone-700"
                  }`}
                >
                  {item.label}
                </button>
              ))}
            </div>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => setWaiterModalOpen(false)}
                className="flex-1 py-2.5 bg-stone-800 hover:bg-stone-700 text-stone-300 font-semibold rounded-xl text-xs"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={callingWaiter}
                onClick={handleCallWaiter}
                className="flex-1 py-2.5 bg-blue-500 hover:bg-blue-600 disabled:opacity-50 text-white font-bold rounded-xl text-xs shadow-md"
              >
                {callingWaiter ? "Calling..." : "Call Server"}
              </button>
            </div>
          </motion.div>
        </div>
      )}

      {/* Request Bill Modal */}
      {billModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <motion.div
            initial={{ scale: 0.95, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            className="bg-stone-900 border border-stone-800 rounded-3xl max-w-sm w-full p-5 shadow-2xl"
          >
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
                <CreditCard className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-bold text-stone-100">Request Bill</h3>
                <p className="text-xs text-stone-400">For {table.table_number}</p>
              </div>
            </div>

            <div className="bg-stone-950 border border-stone-800 rounded-2xl p-4 mb-4">
              <div className="flex justify-between text-xs mb-1">
                <span className="text-stone-400">Current Tab</span>
                <span className="font-bold text-stone-100">
                  {branch?.currency === 'EUR' ? '€' : '$'}{session?.total || '0.00'}
                </span>
              </div>
              <p className="text-[11px] text-stone-400">
                A server will bring the physical receipt and portable card terminal to your table.
              </p>
            </div>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => setBillModalOpen(false)}
                className="flex-1 py-2.5 bg-stone-800 hover:bg-stone-700 text-stone-300 font-semibold rounded-xl text-xs"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={requestingBill}
                onClick={handleRequestBill}
                className="flex-1 py-2.5 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 text-stone-950 font-bold rounded-xl text-xs shadow-md"
              >
                {requestingBill ? "Sending..." : "Bring Bill"}
              </button>
            </div>
          </motion.div>
        </div>
      )}
    </div>
  );
}
