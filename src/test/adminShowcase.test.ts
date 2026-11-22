import { describe, it, expect, vi, beforeEach } from 'vitest';
import { api } from '@/lib/api';

describe('Admin Showcase & Ledger Telemetry Suite', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    sessionStorage.clear();
    localStorage.clear();
  });

  it('fetches real-time payment ledger and revenue statistics', async () => {
    const mockPayments = [
      {
        id: 1,
        transaction_reference: 'TXN-9021',
        amount: '65.00',
        currency: 'EUR',
        payment_method: 'card',
        payment_type: 'full',
        status: 'completed',
        created_at: '2026-11-21T20:00:00Z',
      },
    ];

    const mockStats = {
      total_revenue: 1676,
      net_revenue: 1676,
      refunds_total: 0,
      transactions_count: 9,
      by_method: [
        { payment_method: 'card', total: 1335, count: 6 },
        { payment_method: 'cash', total: 333, count: 2 },
      ],
    };

    vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce({
        ok: true,
        text: async () => JSON.stringify(mockPayments),
      } as Response)
      .mockResolvedValueOnce({
        ok: true,
        text: async () => JSON.stringify(mockStats),
      } as Response);

    const payments = await api.getPayments();
    expect(payments).toHaveLength(1);
    expect(payments[0].transaction_reference).toBe('TXN-9021');

    const stats = await api.getPaymentStats();
    expect(stats.net_revenue).toBe(1676);
    expect(stats.by_method).toHaveLength(2);
  });

  it('issues an immutable refund with justification requirement', async () => {
    const mockRefundResult = {
      message: 'Refund processed successfully.',
      refund: {
        id: 99,
        parent_payment_id: 1,
        amount: -25.00,
        payment_type: 'refund',
        status: 'completed',
        transaction_reference: 'ref_1234567890',
        notes: 'Refund for TXN-9021: Customer charged twice',
      },
      remaining_refundable: 40.00,
    };

    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockRefundResult),
    } as Response);

    const res = await api.refundPayment(1, 'Customer charged twice');
    expect(res.message).toBe('Refund processed successfully.');
    expect(res.refund.amount).toBe(-25.00);
    expect(res.remaining_refundable).toBe(40.00);

    const [url, options] = fetchSpy.mock.calls[0];
    expect(url).toContain('/payments/1/refund');
    expect(options?.method).toBe('POST');
    expect(JSON.parse(options?.body as string)).toEqual({ reason: 'Customer charged twice' });
  });

  it('dispatches a manual walk-in POS order to the kitchen', async () => {
    const mockPosOrder = {
      id: 55,
      order_number: '#POS-101',
      order_type: 'dine_in',
      status: 'placed',
      subtotal: '45.00',
      tax: '3.60',
      total: '48.60',
      guest_name: 'Counter Guest',
      table_id: 1,
      items: [
        {
          id: 101,
          item_name: 'Black Truffle Tagliatelle',
          unit_price: 45.00,
          quantity: 1,
        },
      ],
    };

    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockPosOrder),
    } as Response);

    const payload = {
      table_id: 1,
      guest_name: 'Counter Guest',
      items: [{ menu_item_id: 4, item_name: 'Black Truffle Tagliatelle', unit_price: 45.00, quantity: 1 }],
      notes: 'No salt',
    };

    const res = await api.createPosOrder(payload);
    expect(res.order_number).toBe('#POS-101');
    expect(res.status).toBe('placed');

    const [url, options] = fetchSpy.mock.calls[0];
    expect(url).toContain('/orders');
    expect(options?.method).toBe('POST');
  });

  it('advances kitchen order lifecycle statuses and completes individual dishes', async () => {
    vi.spyOn(global, 'fetch')
      .mockResolvedValueOnce({
        ok: true,
        text: async () => JSON.stringify({ message: 'Order #1024 status updated to preparing' }),
      } as Response)
      .mockResolvedValueOnce({
        ok: true,
        text: async () => JSON.stringify({ message: 'Dish Wagyu Ribeye Steak marked as ready' }),
      } as Response);

    const statusRes = await api.updateOrderStatus(1, 'preparing');
    expect(statusRes.message).toContain('preparing');

    const itemRes = await api.completeKitchenItem(1);
    expect(itemRes.message).toContain('ready');
  });

  it('retrieves compliance audit trail records', async () => {
    const mockAuditLogs = [
      {
        id: 1,
        action: 'qr_token_regenerated',
        payload: { table_number: 'Table 7', reason: 'Routine rotation' },
        ip_address: '127.0.0.1',
        created_at: '2026-11-21T21:00:00Z',
      },
      {
        id: 2,
        action: 'payment_refunded',
        payload: { refund_amount: -20, reason: 'Customer adjustment' },
        ip_address: '127.0.0.1',
        created_at: '2026-11-21T21:15:00Z',
      },
    ];

    vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockAuditLogs),
    } as Response);

    const logs = await api.getAuditLogs();
    expect(logs).toHaveLength(2);
    expect(logs[0].action).toBe('qr_token_regenerated');
    expect(logs[1].action).toBe('payment_refunded');
  });

  it('calls one-click demo data reset endpoint', async () => {
    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify({ message: 'Demo data reset and seeded successfully.' }),
    } as Response);

    const res = await api.resetDemoData();
    expect(res.message).toContain('reset and seeded successfully');

    const [url, options] = fetchSpy.mock.calls[0];
    expect(url).toContain('/demo/reset');
    expect(options?.method).toBe('POST');
  });
});
