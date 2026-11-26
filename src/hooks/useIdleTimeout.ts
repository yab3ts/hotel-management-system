import { useEffect } from "react";
import { useNavigate } from "react-router-dom";

const DEFAULT_IDLE_TIMEOUT_MS = 2 * 60 * 1000; // 2 minutes (#117 Front-desk auto-lock)

/**
 * Automatically locks staff/admin sessions after inactivity by clearing stored credentials
 * and redirecting to the login screen (#117).
 */
export function useIdleTimeout(timeoutMs: number = DEFAULT_IDLE_TIMEOUT_MS) {
  const navigate = useNavigate();

  useEffect(() => {
    const token = localStorage.getItem("token");
    if (!token) return;

    let timerId: ReturnType<typeof setTimeout>;

    const lockSession = () => {
      const currentToken = localStorage.getItem("token");
      if (currentToken) {
        try {
          fetch("/api/auth/logout", {
            method: "POST",
            headers: {
              "Authorization": `Bearer ${currentToken}`,
              "Content-Type": "application/json",
              "Accept": "application/json",
            },
            keepalive: true,
          }).catch(() => {});
        } catch (_) {}
      }
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      navigate("/login?locked=idle", { replace: true });
    };

    const resetTimer = () => {
      clearTimeout(timerId);
      timerId = setTimeout(lockSession, timeoutMs);
    };

    const events: (keyof WindowEventMap)[] = ["mousemove", "keydown", "mousedown", "touchstart", "scroll"];
    events.forEach((evt) => window.addEventListener(evt, resetTimer, { passive: true }));
    resetTimer();

    return () => {
      clearTimeout(timerId);
      events.forEach((evt) => window.removeEventListener(evt, resetTimer));
    };
  }, [navigate, timeoutMs]);
}
