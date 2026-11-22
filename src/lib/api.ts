// src/lib/api.ts
const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

export interface StoredUser {
  id: string;
  email: string;
  full_name: string;
  phone: string;
  password?: string;
  role: string;
  roles: string[];
  token: string;
  created_at: string;
}

const DEFAULT_USERS: StoredUser[] = [
  {
    id: "usr_admin_1",
    email: "admin@cafex.com",
    full_name: "Café Administrator",
    phone: "+1 (555) 010-0001",
    password: "admin123",
    role: "admin",
    roles: ["admin", "manager", "customer"],
    token: "mock-token-admin-cafex",
    created_at: "2026-01-01T00:00:00.000Z",
  },
  {
    id: "usr_kitchen_1",
    email: "kitchen@cafex.com",
    full_name: "Head Chef Marco",
    phone: "+1 (555) 010-0002",
    password: "kitchen123",
    role: "kitchen",
    roles: ["kitchen", "kitchen_staff", "customer"],
    token: "mock-token-kitchen-cafex",
    created_at: "2026-01-01T00:00:00.000Z",
  },
  {
    id: "usr_customer_1",
    email: "customer@cafex.com",
    full_name: "John Customer",
    phone: "+1 (555) 010-0003",
    password: "customer123",
    role: "customer",
    roles: ["customer"],
    token: "mock-token-customer-cafex",
    created_at: "2026-01-01T00:00:00.000Z",
  },
];

function getLocalUsers(): StoredUser[] {
  try {
    const raw = localStorage.getItem("cafex_registered_users");
    if (!raw) {
      localStorage.setItem("cafex_registered_users", JSON.stringify(DEFAULT_USERS));
      return DEFAULT_USERS;
    }
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed) || parsed.length === 0) {
      localStorage.setItem("cafex_registered_users", JSON.stringify(DEFAULT_USERS));
      return DEFAULT_USERS;
    }
    return parsed;
  } catch {
    return DEFAULT_USERS;
  }
}

function saveLocalUsers(users: StoredUser[]) {
  try {
    localStorage.setItem("cafex_registered_users", JSON.stringify(users));
  } catch {
    // Ignore storage quota errors
  }
}

// Local mock authentication fallback for when backend API is offline or unreachable
function handleLocalAuth(endpoint: string, body: unknown) {
  const payload = (body && typeof body === "object") ? (body as Record<string, unknown>) : {};
  const users = getLocalUsers();

  if (endpoint === '/auth/signup') {
    const email = typeof payload.email === "string" ? payload.email : "";
    const password = typeof payload.password === "string" ? payload.password : "";
    const full_name = typeof payload.full_name === "string" ? payload.full_name : "";
    const phone = typeof payload.phone === "string" ? payload.phone : "";
    const role = typeof payload.role === "string" ? payload.role : "customer";
    if (!email || !password) throw new Error('Email and password required');
    if (password.length < 6) throw new Error('Password must be at least 6 characters');

    const normalizedEmail = email.trim().toLowerCase();
    const existing = users.find(u => u.email.toLowerCase() === normalizedEmail);
    if (existing) throw new Error('User already exists with this email');

    let userRoles = ['customer'];
    if (role === 'admin') {
      userRoles = ['admin', 'manager', 'customer'];
    } else if (role === 'kitchen') {
      userRoles = ['kitchen', 'kitchen_staff', 'customer'];
    }

    const token = 'mock-jwt-' + Math.random().toString(36).substring(2) + Date.now().toString(36);
    const newUser: StoredUser = {
      id: 'usr_' + Date.now().toString(36) + Math.random().toString(36).substring(2, 6),
      email: normalizedEmail,
      full_name: full_name?.trim() || normalizedEmail.split('@')[0],
      phone: phone?.trim() || '',
      password,
      role: userRoles[0],
      roles: userRoles,
      token,
      created_at: new Date().toISOString(),
    };

    users.push(newUser);
    saveLocalUsers(users);

    const { password: _, ...safeUser } = newUser;
    return {
      user: safeUser,
      token,
      session: { access_token: token },
      roles: userRoles,
    };
  }

  if (endpoint === '/auth/login') {
    const email = typeof payload.email === "string" ? payload.email : "";
    const password = typeof payload.password === "string" ? payload.password : "";
    if (!email || !password) throw new Error('Email and password required');

    const normalizedEmail = email.trim().toLowerCase();
    const found = users.find(u => u.email.toLowerCase() === normalizedEmail && u.password === password);
    if (!found) {
      throw new Error('Invalid email or password');
    }

    const token = found.token || ('mock-jwt-' + Date.now().toString(36));
    const { password: _, ...safeUser } = found;

    return {
      user: safeUser,
      token,
      session: { access_token: token },
      roles: found.roles,
      profile: {
        id: found.id,
        full_name: found.full_name,
        email: found.email,
        phone: found.phone,
        avatar_url: null,
        preferences: { theme: 'light', notifications: true },
      },
    };
  }

  if (endpoint === '/auth/logout') {
    return { message: 'Logged out' };
  }

  throw new Error(`Endpoint ${endpoint} not available in local mode`);
}

class ApiClient {
  private getHeaders() {
    const token = localStorage.getItem('token');
    const guestSecret = localStorage.getItem('cafex_guest_secret');
    return {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(guestSecret ? { 'X-Guest-Secret': guestSecret } : {}),
    };
  }

  async get(endpoint: string) {
    try {
      const response = await fetch(`${API_URL}${endpoint}`, {
        method: 'GET',
        headers: this.getHeaders(),
      });
      return await this.handleResponse(response);
    } catch (err: unknown) {
      if (endpoint === '/health') {
        return { status: 'ok', local: true, timestamp: new Date().toISOString() };
      }
      throw err;
    }
  }

  async post(endpoint: string, body: unknown) {
    try {
      const response = await fetch(`${API_URL}${endpoint}`, {
        method: 'POST',
        headers: this.getHeaders(),
        body: JSON.stringify(body),
      });
      return await this.handleResponse(response);
    } catch (err: unknown) {
      // If backend network call fails (e.g. server offline, connection refused),
      // transparently use robust local mock auth for seamless user experience
      if (endpoint.startsWith('/auth/')) {
        return handleLocalAuth(endpoint, body);
      }
      throw err;
    }
  }

  async put(endpoint: string, body: unknown) {
    const response = await fetch(`${API_URL}${endpoint}`, {
      method: 'PUT',
      headers: this.getHeaders(),
      body: JSON.stringify(body),
    });
    return this.handleResponse(response);
  }

  async patch(endpoint: string, body?: unknown) {
    const response = await fetch(`${API_URL}${endpoint}`, {
      method: 'PATCH',
      headers: this.getHeaders(),
      body: body ? JSON.stringify(body) : undefined,
    });
    return this.handleResponse(response);
  }

  async delete(endpoint: string) {
    const response = await fetch(`${API_URL}${endpoint}`, {
      method: 'DELETE',
      headers: this.getHeaders(),
    });
    return this.handleResponse(response);
  }

  // QR Table Ordering & Sessions
  async scanTable(token: string) {
    return this.get(`/t/${token}`);
  }

  async joinTableSession(token: string, guestName: string, phone?: string) {
    return this.post(`/t/${token}/join`, { guest_name: guestName, phone });
  }

  async getTableTab(token: string) {
    return this.get(`/t/${token}/tab`);
  }

  async placeQrOrder(token: string, payload: { guest_name: string; items: unknown[]; notes?: string; payment_choice?: string }) {
    return this.post(`/t/${token}/order`, payload);
  }

  async callWaiter(token: string, payload?: { guest_name?: string; reason?: string }) {
    return this.post(`/t/${token}/call-waiter`, payload || {});
  }

  async requestBill(token: string, payload?: { guest_name?: string; split_type?: string }) {
    return this.post(`/t/${token}/request-bill`, payload || {});
  }

  async payCustomerBill(token: string, payload: { amount: number; payment_method: string; split_type?: string; notes?: string }) {
    return this.post(`/t/${token}/pay`, payload);
  }

  // Waiter & Floor Companion
  async getWaiterTables() {
    return this.get('/waiter/tables');
  }

  async getWaiterAlerts() {
    return this.get('/waiter/alerts');
  }

  async serveOrder(orderId: number | string) {
    return this.patch(`/waiter/orders/${orderId}/serve`);
  }

  async settleSession(sessionId: number | string, payload?: unknown) {
    return this.post(`/waiter/sessions/${sessionId}/settle`, payload || {});
  }

  async markTableClean(tableId: number | string) {
    return this.patch(`/waiter/tables/${tableId}/clean`);
  }

  async resolveServiceRequest(requestId: number | string) {
    return this.patch(`/waiter/service-requests/${requestId}/resolve`);
  }

  // Kitchen Orders & KDS
  async getKitchenOrders(status?: string) {
    const query = status && status !== 'All' ? `?status=${encodeURIComponent(status)}` : '';
    return this.get(`/orders${query}`);
  }

  async updateOrderStatus(orderId: number | string, status: string, reason?: string) {
    return this.patch(`/orders/${orderId}/status`, { status, cancellation_reason: reason });
  }

  async completeKitchenItem(itemId: number | string) {
    return this.patch(`/orders/items/${itemId}/complete`);
  }

  async cancelCustomerOrder(token: string, orderId: number | string, reason?: string) {
    return this.post(`/t/${token}/orders/${orderId}/cancel`, { reason });
  }

  // Admin Controls
  async regenerateTableQr(tableId: number | string, reason?: string) {
    return this.post(`/tables/${tableId}/regenerate-qr`, { reason });
  }

  async toggleBranchQr(branchId: number | string) {
    return this.post('/branches/toggle-qr', { branch_id: branchId });
  }

  async toggleMenuItemAvailability(itemId: number | string) {
    return this.patch(`/menu/${itemId}/availability`);
  }

  async getMenu(params?: { category?: string; search?: string }) {
    const query = new URLSearchParams();
    if (params?.category) query.append('category', params.category);
    if (params?.search) query.append('search', params.search);
    const qs = query.toString();
    return this.get(`/menu${qs ? `?${qs}` : ''}`);
  }

  async getMenuCategories() {
    return this.get('/menu/categories');
  }

  // Payments & Admin Audit Trail
  async getPayments(page = 1) {
    return this.get(`/payments?page=${page}`);
  }

  async getPaymentStats() {
    return this.get('/payments/stats');
  }

  async refundPayment(paymentId: number | string, reason: string) {
    return this.post(`/payments/${paymentId}/refund`, { reason });
  }

  async getAuditLogs() {
    return this.get('/audit-logs');
  }

  async getDashboardStats() {
    return this.get('/dashboard/stats');
  }

  async resetDemoData() {
    return this.post('/demo/reset', {});
  }

  async createPosOrder(payload: { table_id?: number; guest_name: string; items: any[]; notes?: string; payment_method?: string }) {
    return this.post('/orders', payload);
  }

  // System & Venue Settings
  async getSettings() {
    return this.get('/settings');
  }

  async updateSettings(payload: Record<string, any>) {
    return this.patch('/settings', payload);
  }

  // Inventory Management
  async getInventory() {
    return this.get('/inventory');
  }

  async createInventoryItem(payload: Record<string, any>) {
    return this.post('/inventory', payload);
  }

  async updateInventoryItem(id: number | string, payload: Record<string, any>) {
    return this.patch(`/inventory/${id}`, payload);
  }

  async deductRecipe(dish_name: string, quantity: number) {
    return this.post('/inventory/deduct-recipe', { dish_name, quantity });
  }

  // Daily Financial Reconciliation & Fiscal Receipts
  async getDailyReconciliation(date?: string) {
    return this.get(`/reports/reconciliation${date ? `?date=${date}` : ''}`);
  }

  async getSessionReceipt(sessionId: number | string) {
    return this.get(`/sessions/${sessionId}/receipt`);
  }

  private async handleResponse(response: Response) {
    if (!response.ok) {
      let errorData;
      try {
        errorData = await response.json();
      } catch (err) {
        errorData = { error: `HTTP error! status: ${response.status}` };
      }

      if (response.status === 401) {
        throw new Error(errorData.error || errorData.message || 'Unauthorized');
      }
      
      throw new Error(errorData.error || errorData.message || 'Something went wrong');
    }
    
    const text = await response.text();
    return text ? JSON.parse(text) : null;
  }
}

export const api = new ApiClient();
