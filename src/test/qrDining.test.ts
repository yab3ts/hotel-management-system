import { describe, it, expect, vi, beforeEach } from 'vitest';
import { api } from '@/lib/api';

describe('QR Dining API & Session Handling', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    sessionStorage.clear();
    localStorage.clear();
  });

  it('scans a table token and resolves session details', async () => {
    const mockResponse = {
      table: {
        id: 2,
        table_number: 'Table 7',
        name: 'Main Center 7',
        capacity: 4,
        zone: 'Main',
        status: 'occupied',
        qr_token: 'table-7',
      },
      branch: {
        id: 1,
        name: 'Café X Milan (Downtown)',
        currency: 'EUR',
        tax_rate: '10.00',
        service_charge: '5.00',
      },
      session: {
        id: 1,
        session_token: 'sess_milan_7',
        status: 'active',
        total: '120.75',
      },
    };

    vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockResponse),
    } as Response);

    const res = await api.scanTable('table-7');
    expect(res.table.table_number).toBe('Table 7');
    expect(res.session.status).toBe('active');
    expect(res.branch.currency).toBe('EUR');
  });

  it('submits a QR order with co-diner guest name and special notes', async () => {
    const mockOrderResult = {
      message: 'Order sent to the kitchen!',
      order: {
        id: 10,
        guest_name: 'Abel',
        order_number: '#512',
        total: '35.00',
      },
      session: {
        id: 1,
        total: '155.75',
      },
    };

    vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockOrderResult),
    } as Response);

    const res = await api.placeQrOrder('table-7', {
      guest_name: 'Abel',
      items: [
        { item_name: 'Wagyu Ribeye Steak', unit_price: 85, quantity: 1, special_notes: 'Medium rare please' },
      ],
      payment_choice: 'pay_later',
    });

    expect(res.message).toBe('Order sent to the kitchen!');
    expect(res.order.guest_name).toBe('Abel');
    expect(res.session.total).toBe('155.75');
  });

  it('calls the waiter for a table with reason', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify({ message: 'Waiter has been called!', request_id: 42 }),
    } as Response);

    const res = await api.callWaiter('table-7', {
      guest_name: 'Abel',
      reason: 'water',
    });

    expect(res.message).toContain('Waiter has been called');
    expect(res.request_id).toBe(42);
  });

  it('automatically transmits X-Guest-Secret header when guest secret is stored in localStorage', async () => {
    localStorage.setItem('cafex_guest_secret', 'gs_secret_abcdef123456');

    const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify({ status: 'ok' }),
    } as Response);

    await api.getTableTab('8f3k2x');

    expect(fetchSpy).toHaveBeenCalled();
    const calledHeaders = fetchSpy.mock.calls[0][1]?.headers as Record<string, string>;
    expect(calledHeaders['X-Guest-Secret']).toBe('gs_secret_abcdef123456');
  });

  it('correctly calculates split bill equally with remainder cent balancing', () => {
    const total = 100.00;
    const splitCount = 3;
    const baseShare = Math.floor((total / splitCount) * 100) / 100; // 33.33
    const remainder = Math.round((total - baseShare * splitCount) * 100) / 100; // 0.01

    expect(baseShare).toBe(33.33);
    expect(remainder).toBe(0.01);
    // 33.33 + 33.33 + (33.33 + 0.01) === 100.00
    expect(baseShare * (splitCount - 1) + (baseShare + remainder)).toBeCloseTo(total, 2);
  });

  it('correctly calculates item split with proportional tax and service charges', () => {
    const selectedItemPrice = 20.00;
    const taxRate = 0.10; // 10%
    const serviceRate = 0.05; // 5%

    const tax = Math.round(selectedItemPrice * taxRate * 100) / 100; // 2.00
    const service = Math.round(selectedItemPrice * serviceRate * 100) / 100; // 1.00
    const totalShare = selectedItemPrice + tax + service; // 23.00

    expect(tax).toBe(2.00);
    expect(service).toBe(1.00);
    expect(totalShare).toBe(23.00);
  });

  it('submits a pay customer bill request', async () => {
    const mockPaymentRes = {
      message: 'Payment received. Remaining balance updated.',
      payment: {
        id: 1,
        amount: 23.00,
        payment_method: 'card',
        status: 'completed',
      },
      is_fully_paid: false,
    };

    vi.spyOn(global, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: async () => JSON.stringify(mockPaymentRes),
    } as Response);

    const res: any = await api.payCustomerBill('table-7', {
      amount: 23.00,
      payment_method: 'card',
      split_type: 'item',
    });

    expect(res.payment.amount).toBe(23.00);
    expect(res.is_fully_paid).toBe(false);
  });
});
