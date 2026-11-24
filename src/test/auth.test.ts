import { describe, it, expect, beforeEach } from "vitest";
import { api } from "../lib/api";

describe("Authentication API & Local Fallback", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it("should successfully sign in with standard demo accounts", async () => {
    // Admin login
    const adminRes = await api.post("/auth/login", {
      email: "admin@cafex.com",
      password: "admin123",
    });
    expect(adminRes.user.email).toBe("admin@cafex.com");
    expect(adminRes.token).toBeDefined();
    expect(adminRes.roles).toContain("admin");

    // Kitchen login
    const kitchenRes = await api.post("/auth/login", {
      email: "kitchen@cafex.com",
      password: "kitchen123",
    });
    expect(kitchenRes.user.email).toBe("kitchen@cafex.com");
    expect(kitchenRes.roles).toContain("kitchen");
    expect(kitchenRes.roles).toContain("kitchen_staff");

    // Customer login
    const customerRes = await api.post("/auth/login", {
      email: "customer@cafex.com",
      password: "customer123",
    });
    expect(customerRes.user.email).toBe("customer@cafex.com");
    expect(customerRes.roles).toContain("customer");
  });

  it("should reject invalid login credentials", async () => {
    await expect(
      api.post("/auth/login", {
        email: "customer@cafex.com",
        password: "wrongpassword",
      })
    ).rejects.toThrow("Invalid email or password");
  });

  it("should register a new user and allow them to sign in", async () => {
    const uniqueEmail = `testuser_${Date.now()}@example.com`;
    const signupRes = await api.post("/auth/signup", {
      full_name: "Test User",
      email: uniqueEmail,
      password: "password123",
      role: "customer",
      phone: "+1 234 567 8900",
    });

    expect(signupRes.user.email).toBe(uniqueEmail);
    expect(signupRes.token).toBeDefined();
    expect(signupRes.roles).toContain("customer");

    // Log in with newly registered account
    const loginRes = await api.post("/auth/login", {
      email: uniqueEmail,
      password: "password123",
    });

    expect(loginRes.user.email).toBe(uniqueEmail);
    expect(loginRes.token).toBeDefined();
  });

  it("should prevent privilege escalation on public signup and always assign customer role", async () => {
    const attemptedAdminEmail = `attacker_${Date.now()}@example.com`;
    const signupRes = await api.post("/auth/signup", {
      full_name: "Privilege Escalation Tester",
      email: attemptedAdminEmail,
      password: "password123",
      role: "admin",
    });

    expect(signupRes.roles).not.toContain("admin");
    expect(signupRes.roles).not.toContain("manager");
    expect(signupRes.roles).toContain("customer");
  });

  it("should reject signup with password less than 6 characters", async () => {
    await expect(
      api.post("/auth/signup", {
        email: "short@example.com",
        password: "123",
      })
    ).rejects.toThrow("Password must be at least 6 characters");
  });

  it("should reject duplicate signup with existing email", async () => {
    await expect(
      api.post("/auth/signup", {
        email: "admin@cafex.com",
        password: "password123",
      })
    ).rejects.toThrow("User already exists with this email");
  });
});
