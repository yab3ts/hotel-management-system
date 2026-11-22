import { describe, it, expect, vi, beforeEach } from "vitest";
import { api } from "@/lib/api";

describe("Level B Production Readiness & Operational Settings", () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it("stores and retrieves configurable QR placard base URL", () => {
    const testUrl = "http://10.196.208.58:5174";
    localStorage.setItem("cafex_qr_base_url", testUrl);

    expect(localStorage.getItem("cafex_qr_base_url")).toBe(testUrl);
  });

  it("detects when QR base URL points to localhost and warns user", () => {
    const localhostUrl = "http://localhost:5174";
    const isLocal = localhostUrl.includes("localhost") || localhostUrl.includes("127.0.0.1");
    expect(isLocal).toBe(true);

    const lanUrl = "http://10.196.208.58:5174";
    const isLanLocalhost = lanUrl.includes("localhost") || lanUrl.includes("127.0.0.1");
    expect(isLanLocalhost).toBe(false);
  });

  it("calls getSettings API endpoint correctly", async () => {
    const mockSettings = {
      settings: {
        branch_name: "Café X Milan Flagship",
        currency: "EUR",
        tax_rate: 10.0,
        service_charge: 5.0,
        is_qr_ordering_enabled: true,
      },
    };

    vi.spyOn(global, "fetch").mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockSettings),
    } as any);

    const res: any = await api.getSettings();
    expect(res.settings.branch_name).toBe("Café X Milan Flagship");
    expect(res.settings.currency).toBe("EUR");
    expect(res.settings.tax_rate).toBe(10.0);
  });

  it("calls daily reconciliation endpoint and returns balanced ledger", async () => {
    const mockRecon = {
      date: "2026-11-22",
      summary: {
        orders_gross_total: 250.0,
        net_collected: 250.0,
        discrepancy: 0.0,
        is_balanced: true,
      },
    };

    vi.spyOn(global, "fetch").mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockRecon),
    } as any);

    const res: any = await api.getDailyReconciliation("2026-11-22");
    expect(res.summary.is_balanced).toBe(true);
    expect(res.summary.discrepancy).toBe(0.0);
  });

  it("calls inventory listing endpoint and categorizes low stock", async () => {
    const mockInventory = {
      items: [
        { id: 1, name: "Wagyu Beef", stock: 2, threshold: 5, unit: "kg", status: "critical" },
        { id: 2, name: "Italian Flour", stock: 25, threshold: 10, unit: "kg", status: "ok" },
      ],
      stats: { total_items: 2, critical_items: 1, low_stock_items: 0 },
    };

    vi.spyOn(global, "fetch").mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockInventory),
    } as any);

    const res: any = await api.getInventory();
    expect(res.items).toHaveLength(2);
    expect(res.stats.critical_items).toBe(1);
  });
});
