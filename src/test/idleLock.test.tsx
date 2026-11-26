import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, act } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { useIdleTimeout } from "@/hooks/useIdleTimeout";

function ProtectedStaffScreen() {
  useIdleTimeout(120_000); // 2-minute front-desk auto-lock (#117)
  return <div data-testid="staff-screen">Active Front Desk</div>;
}

function LocationSpy() {
  const loc = useLocation();
  return <div data-testid="loc">{loc.pathname + loc.search}</div>;
}

describe("Front-Desk Auto-Lock (#117)", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    localStorage.setItem("token", "staff-bearer-token-123");
    localStorage.setItem("user", JSON.stringify({ email: "admin@cafex.com", role: "admin" }));
  });

  afterEach(() => {
    vi.useRealTimers();
    localStorage.clear();
  });

  it("revokes token on server via /api/auth/logout, clears storage, and redirects to /login", () => {
    const fetchSpy = vi.spyOn(global, "fetch").mockImplementation(() => Promise.resolve(new Response(null, { status: 200 })));

    const { getByTestId } = render(
      <MemoryRouter initialEntries={["/admin"]}>
        <Routes>
          <Route path="/admin" element={<ProtectedStaffScreen />} />
          <Route path="/login" element={<LocationSpy />} />
        </Routes>
      </MemoryRouter>
    );

    expect(getByTestId("staff-screen")).toBeInTheDocument();
    expect(localStorage.getItem("token")).toBe("staff-bearer-token-123");

    // Simulate user activity resets timer cleanly at 90s
    act(() => {
      vi.advanceTimersByTime(90_000);
      window.dispatchEvent(new Event("keydown"));
      vi.advanceTimersByTime(90_000);
    });
    expect(localStorage.getItem("token")).toBe("staff-bearer-token-123");
    expect(fetchSpy).not.toHaveBeenCalled();

    // Advance past full 120s idle window with no interaction -> auto-lock triggers
    act(() => {
      vi.advanceTimersByTime(35_000);
    });

    expect(fetchSpy).toHaveBeenCalledWith("/api/auth/logout", expect.objectContaining({
      method: "POST",
      headers: expect.objectContaining({
        Authorization: "Bearer staff-bearer-token-123",
      }),
    }));
    expect(localStorage.getItem("token")).toBeNull();
    expect(localStorage.getItem("user")).toBeNull();
    expect(getByTestId("loc").textContent).toBe("/login?locked=idle");

    fetchSpy.mockRestore();
  });
});
