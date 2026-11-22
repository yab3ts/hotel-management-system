const express = require('express');
const cors = require('cors');
const crypto = require('crypto');

const app = express();
const PORT = 5000;

app.use(cors({ origin: true, credentials: true }));
app.use(express.json());

function generateToken() {
  return crypto.randomBytes(32).toString('hex');
}

const users = [
  {
    id: 'usr_admin_1',
    email: 'admin@cafex.com',
    password: 'admin123',
    full_name: 'Café Administrator',
    phone: '+1 (555) 010-0001',
    role: 'admin',
    roles: ['admin', 'manager', 'customer'],
    token: generateToken(),
    created_at: '2026-01-01T00:00:00.000Z',
  },
  {
    id: 'usr_kitchen_1',
    email: 'kitchen@cafex.com',
    password: 'kitchen123',
    full_name: 'Head Chef Marco',
    phone: '+1 (555) 010-0002',
    role: 'kitchen',
    roles: ['kitchen', 'kitchen_staff', 'customer'],
    token: generateToken(),
    created_at: '2026-01-01T00:00:00.000Z',
  },
  {
    id: 'usr_customer_1',
    email: 'customer@cafex.com',
    password: 'customer123',
    full_name: 'John Customer',
    phone: '+1 (555) 010-0003',
    role: 'customer',
    roles: ['customer'],
    token: generateToken(),
    created_at: '2026-01-01T00:00:00.000Z',
  },
];

app.post('/api/auth/signup', (req, res) => {
  const { email, password, full_name, phone, role = 'customer' } = req.body;
  if (!email || !password) return res.status(400).json({ error: 'Email and password required' });
  if (password.length < 6) return res.status(400).json({ error: 'Password must be at least 6 characters' });
  if (users.find(u => u.email.toLowerCase() === email.toLowerCase())) {
    return res.status(400).json({ error: 'User already exists' });
  }

  const token = generateToken();
  let userRoles = ['customer'];
  if (role === 'admin') {
    userRoles = ['admin', 'manager', 'customer'];
  } else if (role === 'kitchen') {
    userRoles = ['kitchen', 'kitchen_staff', 'customer'];
  }

  const user = {
    id: crypto.randomUUID(),
    email: email.toLowerCase(),
    full_name: full_name || email.split('@')[0],
    phone: phone || '',
    role: userRoles[0],
    roles: userRoles,
    created_at: new Date().toISOString(),
  };
  users.push({ ...user, password, token });

  res.status(201).json({ user, token, session: { access_token: token }, roles: userRoles });
});

app.post('/api/auth/login', (req, res) => {
  const { email, password } = req.body;
  if (!email || !password) return res.status(400).json({ error: 'Email and password required' });

  const found = users.find(u => u.email.toLowerCase() === email.toLowerCase() && u.password === password);
  if (!found) return res.status(401).json({ error: 'Invalid email or password' });

  const { password: _, token, ...user } = found;
  res.json({
    user,
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
  });
});

app.post('/api/auth/logout', (req, res) => {
  res.json({ message: 'Logged out' });
});

app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', version: '3.0-mock', timestamp: new Date().toISOString() });
});

app.listen(PORT, () => console.log(`Mock auth server running on port ${PORT}`));
