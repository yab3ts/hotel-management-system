import React from "react";
import { Link } from "react-router-dom";
import { ShieldCheck, ArrowLeft, Lock, FileText, CheckCircle2 } from "lucide-react";

export default function PrivacyPolicyPage() {
  return (
    <div className="min-h-screen bg-background text-foreground py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-3xl mx-auto">
        <Link
          to="/"
          className="inline-flex items-center gap-2 text-xs font-semibold text-muted-foreground hover:text-foreground mb-8"
        >
          <ArrowLeft className="w-4 h-4" /> Back to Home
        </Link>

        <div className="flex items-center gap-3 mb-6">
          <div className="w-12 h-12 rounded-2xl gradient-warm flex items-center justify-center text-primary-foreground shadow-lg">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <h1 className="font-display text-2xl font-black text-foreground">Privacy Policy & Data Protection</h1>
            <p className="text-xs text-muted-foreground">Effective Date: November 2026 · Café X Hospitality Platform</p>
          </div>
        </div>

        <div className="glass-card-elevated p-6 sm:p-8 rounded-3xl space-y-6 text-xs text-muted-foreground leading-relaxed">
          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <Lock className="w-4 h-4 text-primary" /> 1. Ephemeral QR Table Dining Data
            </h2>
            <p>
              When you scan a Café X table QR code, we collect only the minimal data necessary to route your order to the kitchen: your first name (or alias) and optional phone number. We do not require account creation, password setup, or invasive tracking cookies. When your dining session settles and closes, identifying table presence data is decoupled from active memory.
            </p>
          </section>

          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <ShieldCheck className="w-4 h-4 text-emerald-400" /> 2. PCI-DSS Compliance & Zero Card Storage
            </h2>
            <p>
              <strong>We never store, process, or transmit raw credit card numbers (PAN) or security codes (CVV/CVC) on Café X servers.</strong> All electronic payments (Credit/Debit Card, Apple Pay, Google Pay, Mobile Money) are processed through tokenized, certified payment gateway providers (e.g., Stripe, Chapa, Square). Only anonymized transaction references and payment status tokens are retained for fiscal ledger balancing.
            </p>
          </section>

          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <FileText className="w-4 h-4 text-amber-400" /> 3. Data Retention & Fiscal Records
            </h2>
            <p>
              Financial records (transaction timestamp, amount, VAT tax rate, receipt number) are retained as required by applicable tax directives and hospitality fiscal regulations. Security audit trails (IP addresses, token rotation logs) are automatically purged after 90 days.
            </p>
          </section>

          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4 text-blue-400" /> 4. Your Rights (GDPR & Data Protection)
            </h2>
            <p>
              You have the right to request access to any personal data associated with registered diner accounts, request correction of inaccurate data, or request permanent deletion ("right to be forgotten"). To exercise these rights, email our data privacy team at <a href="mailto:privacy@cafex.app" className="text-primary underline">privacy@cafex.app</a>.
            </p>
          </section>
        </div>
      </div>
    </div>
  );
}
