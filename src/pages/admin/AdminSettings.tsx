import React, { useEffect, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { 
  Settings, 
  Store, 
  Coins, 
  Receipt, 
  Percent, 
  Clock, 
  QrCode, 
  ShieldAlert, 
  CheckCircle2, 
  AlertTriangle, 
  Save, 
  RefreshCw, 
  Printer, 
  Sliders, 
  CreditCard,
  Building2,
  Globe
} from "lucide-react";
import { AdminLayout as AppLayout } from "@/components/AdminLayout";
import { PageHeader } from "@/components/PageHeader";
import { api } from "@/lib/api";
import { toast } from "@/hooks/use-toast";

interface VenueSettings {
  branch_id: number;
  branch_name: string;
  tenant_name: string;
  currency: string;
  tax_rate: number;
  service_charge: number;
  is_qr_ordering_enabled: boolean;
  session_timeout_minutes: number;
  qr_base_url: string;
  demo_mode: boolean;
  gateway_mode: string;
}

export default function AdminSettings() {
  const [settings, setSettings] = useState<VenueSettings>({
    branch_id: 1,
    branch_name: "Café X Milan Flagship",
    tenant_name: "Café X Hospitality Group",
    currency: "EUR",
    tax_rate: 10.0,
    service_charge: 5.0,
    is_qr_ordering_enabled: true,
    session_timeout_minutes: 180,
    qr_base_url: window.location.origin,
    demo_mode: true,
    gateway_mode: "sandbox_simulator",
  });

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [reconciliation, setReconciliation] = useState<any>(null);
  const [loadingRecon, setLoadingRecon] = useState(false);

  const fetchSettings = async () => {
    try {
      setLoading(true);
      const res = await api.getSettings();
      if (res?.settings) {
        setSettings({
          ...res.settings,
          qr_base_url: localStorage.getItem("cafex_qr_base_url") || res.settings.qr_base_url || window.location.origin,
        });
      }
    } catch (err: any) {
      console.warn("Could not fetch remote settings, using local defaults:", err);
    } finally {
      setLoading(false);
    }
  };

  const fetchReconciliation = async () => {
    try {
      setLoadingRecon(true);
      const res = await api.getDailyReconciliation();
      setReconciliation(res);
    } catch (err: any) {
      console.warn("Could not fetch reconciliation:", err);
    } finally {
      setLoadingRecon(false);
    }
  };

  useEffect(() => {
    fetchSettings();
    fetchReconciliation();
  }, []);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      // Persist base URL locally for QR placard generator
      localStorage.setItem("cafex_qr_base_url", settings.qr_base_url);

      await api.updateSettings({
        branch_name: settings.branch_name,
        currency: settings.currency,
        tax_rate: settings.tax_rate,
        service_charge: settings.service_charge,
        is_qr_ordering_enabled: settings.is_qr_ordering_enabled,
        session_timeout_minutes: settings.session_timeout_minutes,
      });

      toast({
        title: "Settings Saved",
        description: "Venue parameters and QR base URL updated successfully.",
      });
    } catch (err: any) {
      toast({
        title: "Save Failed",
        description: err.message || "Could not update settings.",
        variant: "destructive",
      });
    } finally {
      setSaving(false);
    }
  };

  const isLocalhostUrl = settings.qr_base_url.includes("localhost") || settings.qr_base_url.includes("127.0.0.1");

  return (
    <AppLayout>
      <PageHeader
        title="Venue & System Settings"
        subtitle="Configure branch parameters, fiscal tax rules, QR placards domain, and payment gateways"
      />

      {loading ? (
        <div className="flex items-center justify-center p-12">
          <RefreshCw className="w-8 h-8 animate-spin text-primary" />
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Main Settings Form */}
          <form onSubmit={handleSave} className="lg:col-span-2 space-y-6">
            {/* Identity & Branch */}
            <div className="glass-card-elevated p-6 rounded-2xl">
              <h3 className="text-sm font-bold text-foreground flex items-center gap-2 mb-4">
                <Store className="w-4 h-4 text-primary" /> Venue Identity & Regional Settings
              </h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">Branch Name</label>
                  <input
                    type="text"
                    value={settings.branch_name}
                    onChange={(e) => setSettings({ ...settings, branch_name: e.target.value })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-medium"
                    required
                  />
                </div>
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">Operating Currency</label>
                  <select
                    value={settings.currency}
                    onChange={(e) => setSettings({ ...settings, currency: e.target.value })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-semibold"
                  >
                    <option value="EUR">EUR (€) — Eurozone</option>
                    <option value="USD">USD ($) — United States</option>
                    <option value="GBP">GBP (£) — British Pound</option>
                  </select>
                </div>
              </div>
            </div>

            {/* Financial & Fiscal Rates */}
            <div className="glass-card-elevated p-6 rounded-2xl">
              <h3 className="text-sm font-bold text-foreground flex items-center gap-2 mb-4">
                <Percent className="w-4 h-4 text-emerald-400" /> Taxes & Service Charges (Receipt Breakdown)
              </h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">VAT / Sales Tax Rate (%)</label>
                  <input
                    type="number"
                    step="0.1"
                    min="0"
                    max="50"
                    value={settings.tax_rate}
                    onChange={(e) => setSettings({ ...settings, tax_rate: parseFloat(e.target.value) || 0 })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-bold"
                  />
                  <p className="text-[10px] text-muted-foreground mt-1">
                    Computed transparently on food & drinks subtotal.
                  </p>
                </div>
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">Hospitality Service Charge (%)</label>
                  <input
                    type="number"
                    step="0.1"
                    min="0"
                    max="30"
                    value={settings.service_charge}
                    onChange={(e) => setSettings({ ...settings, service_charge: parseFloat(e.target.value) || 0 })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-bold"
                  />
                  <p className="text-[10px] text-muted-foreground mt-1">
                    Distributed to floor waitstaff & kitchen brigade.
                  </p>
                </div>
              </div>
            </div>

            {/* QR Table Ordering & Domain Base URL */}
            <div className="glass-card-elevated p-6 rounded-2xl">
              <h3 className="text-sm font-bold text-foreground flex items-center gap-2 mb-2">
                <QrCode className="w-4 h-4 text-amber-400" /> QR Ordering & Placard Domain Configuration
              </h3>
              <p className="text-xs text-muted-foreground mb-4">
                Printed table QR codes encode this base URL so customers scan directly to the menu.
              </p>

              <div className="space-y-3 text-xs">
                <div>
                  <label className="text-muted-foreground font-semibold block mb-1">QR Code Base URL</label>
                  <input
                    type="text"
                    value={settings.qr_base_url}
                    onChange={(e) => setSettings({ ...settings, qr_base_url: e.target.value })}
                    className="w-full bg-secondary border border-border rounded-xl px-3 py-2 text-foreground font-mono font-medium"
                    placeholder="https://your-domain.com or http://10.196.208.58:5174"
                    required
                  />
                </div>

                <div className="flex flex-wrap gap-2 text-[11px]">
                  <button
                    type="button"
                    onClick={() => setSettings({ ...settings, qr_base_url: window.location.origin })}
                    className="px-2.5 py-1 rounded-lg bg-secondary border border-border hover:bg-muted text-foreground font-medium"
                  >
                    Current Origin ({window.location.origin})
                  </button>
                  <button
                    type="button"
                    onClick={() => setSettings({ ...settings, qr_base_url: `http://${window.location.hostname}:5174` })}
                    className="px-2.5 py-1 rounded-lg bg-secondary border border-border hover:bg-muted text-foreground font-medium"
                  >
                    Host IP ({window.location.hostname}:5174)
                  </button>
                  <button
                    type="button"
                    onClick={() => setSettings({ ...settings, qr_base_url: "https://demo.cafex.app" })}
                    className="px-2.5 py-1 rounded-lg bg-secondary border border-border hover:bg-muted text-foreground font-medium"
                  >
                    Production HTTPS (demo.cafex.app)
                  </button>
                </div>

                {/* Localhost Warning Notice */}
                {isLocalhostUrl && (
                  <div className="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs flex items-start gap-2.5">
                    <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                    <div>
                      <p className="font-bold">Notice for Mobile Hardware Demonstrations:</p>
                      <p className="text-[11px] text-amber-400/90 mt-0.5">
                        QR codes with <code className="bg-amber-950/40 px-1 py-0.5 rounded">localhost</code> cannot be scanned by separate physical smartphones. For live mobile phone scans, switch the Base URL to your Wi-Fi LAN IP or an HTTPS tunnel.
                      </p>
                    </div>
                  </div>
                )}
              </div>

              {/* QR Pause Toggle */}
              <div className="pt-4 mt-4 border-t border-border flex items-center justify-between text-xs">
                <div>
                  <p className="font-bold text-foreground">QR Self-Ordering Status</p>
                  <p className="text-[11px] text-muted-foreground">
                    When paused, table scans notify diners to order with waitstaff.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setSettings({ ...settings, is_qr_ordering_enabled: !settings.is_qr_ordering_enabled })}
                  className={`px-3 py-1.5 rounded-xl font-bold text-xs transition-colors ${
                    settings.is_qr_ordering_enabled
                      ? "bg-emerald-500/15 border border-emerald-500/30 text-emerald-400"
                      : "bg-destructive/15 border border-destructive/30 text-destructive"
                  }`}
                >
                  {settings.is_qr_ordering_enabled ? "Enabled (Active)" : "Paused (Killswitch)"}
                </button>
              </div>
            </div>

            <div className="flex justify-end">
              <button
                type="submit"
                disabled={saving}
                className="gradient-warm text-primary-foreground font-bold px-6 py-2.5 rounded-xl text-xs flex items-center gap-2 shadow-lg disabled:opacity-50"
              >
                <Save className="w-4 h-4" /> {saving ? "Saving Changes..." : "Save Venue Settings"}
              </button>
            </div>
          </form>

          {/* Right Sidebar: Daily Reconciliation & Compliance Summary */}
          <div className="space-y-6">
            {/* End of Day Reconciliation */}
            <div className="glass-card-elevated p-5 rounded-2xl">
              <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-bold text-foreground flex items-center gap-1.5">
                  <Receipt className="w-4 h-4 text-primary" /> Daily Cash & Ledger Reconciliation
                </h3>
                <button
                  onClick={fetchReconciliation}
                  disabled={loadingRecon}
                  className="p-1 text-muted-foreground hover:text-foreground"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${loadingRecon ? "animate-spin" : ""}`} />
                </button>
              </div>

              {reconciliation ? (
                <div className="space-y-3 text-xs">
                  <div className="p-3 rounded-xl bg-secondary/50 border border-border space-y-2">
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Orders Gross:</span>
                      <span className="font-bold text-foreground">€{reconciliation.summary?.orders_gross_total?.toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Net Collected:</span>
                      <span className="font-bold text-emerald-400">€{reconciliation.summary?.net_collected?.toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Open Tabs Balance:</span>
                      <span className="font-bold text-amber-400">€{reconciliation.summary?.unpaid_open_tabs?.toFixed(2)}</span>
                    </div>
                    <div className="border-t border-border pt-2 flex justify-between font-bold">
                      <span>Ledger Discrepancy:</span>
                      <span className={reconciliation.summary?.is_balanced ? "text-emerald-400" : "text-destructive"}>
                        €{reconciliation.summary?.discrepancy?.toFixed(2)}
                      </span>
                    </div>
                  </div>

                  <div className={`p-2.5 rounded-xl text-[11px] font-semibold flex items-center gap-2 ${
                    reconciliation.summary?.is_balanced
                      ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"
                      : "bg-destructive/10 text-destructive border border-destructive/20"
                  }`}>
                    {reconciliation.summary?.is_balanced ? (
                      <>
                        <CheckCircle2 className="w-4 h-4 flex-shrink-0" />
                        <span>Daily register balances 100% with 0.00 variance.</span>
                      </>
                    ) : (
                      <>
                        <AlertTriangle className="w-4 h-4 flex-shrink-0" />
                        <span>Discrepancy detected: cash/card variance under review.</span>
                      </>
                    )}
                  </div>
                </div>
              ) : (
                <p className="text-xs text-muted-foreground">Loading reconciliation telemetry...</p>
              )}
            </div>

            {/* Demo & Gateway Safeguards */}
            <div className="glass-card-elevated p-5 rounded-2xl">
              <h3 className="text-sm font-bold text-foreground flex items-center gap-1.5 mb-3">
                <ShieldAlert className="w-4 h-4 text-amber-400" /> Operational Safeguards (Level B)
              </h3>
              <ul className="text-xs space-y-2 text-muted-foreground">
                <li className="flex items-center gap-2">
                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" />
                  <span>Production Guard: Demo reset strictly disabled in production.</span>
                </li>
                <li className="flex items-center gap-2">
                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" />
                  <span>Zero-Trust: Client prices ignored; line items recalculated from DB.</span>
                </li>
                <li className="flex items-center gap-2">
                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" />
                  <span>HMAC Webhook: Card gateway transactions verified with SHA-256.</span>
                </li>
                <li className="flex items-center gap-2">
                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" />
                  <span>PCI Compliance: No payment card PAN or CVV stored on premises.</span>
                </li>
              </ul>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
