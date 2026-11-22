import React from "react";
import { Link } from "react-router-dom";
import { FileText, ArrowLeft, Utensils, AlertTriangle, CreditCard, Shield } from "lucide-react";

export default function TermsPage() {
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
            <FileText className="w-6 h-6" />
          </div>
          <div>
            <h1 className="font-display text-2xl font-black text-foreground">Terms of Service & Dining Rules</h1>
            <p className="text-xs text-muted-foreground">Effective Date: November 2026 · Café X Hospitality Platform</p>
          </div>
        </div>

        <div className="glass-card-elevated p-6 sm:p-8 rounded-3xl space-y-6 text-xs text-muted-foreground leading-relaxed">
          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <Utensils className="w-4 h-4 text-primary" /> 1. QR Table Ordering & Shared Sessions
            </h2>
            <p>
              Café X provides table-based mobile ordering. When you scan a table QR code, you participate in that table's shared dining tab. All diners joined to the table may view running orders and contribute items. Customers are responsible for verifying orders and item notes prior to submission.
            </p>
          </section>

          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-destructive" /> 2. Allergens & Dietary Requirements
            </h2>
            <p>
              Menu items contain detailed descriptions and allergen badges (e.g., Gluten-Free, Dairy, Nuts, Vegan). While our kitchen adheres to strict hygiene standards, dishes are prepared in environments where common allergens are handled. <strong>If you have severe, life-threatening allergies, you must inform your floor server directly in addition to entering order notes.</strong>
            </p>
          </section>

          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <CreditCard className="w-4 h-4 text-emerald-400" /> 3. Bill Splitting & Settlement
            </h2>
            <p>
              Diners may settle tabs via full payment, equal division across party members, or granular dish-by-dish split. Once an order enters preparation in the kitchen, it represents an irrevocable purchase contract. Unsettled balances upon departure will be billed to the registered card or referred to floor management.
            </p>
          </section>

          <section>
            <h2 className="text-sm font-bold text-foreground mb-2 flex items-center gap-2">
              <Shield className="w-4 h-4 text-blue-400" /> 4. Order Cancellations & Refunds
            </h2>
            <p>
              Orders may be cancelled self-service via the table screen while in "Placed" state before kitchen acceptance. Once preparation begins, cancellations and refunds require authorized floor manager review. Refunds are processed back to the original payment method or issued as store credit.
            </p>
          </section>
        </div>
      </div>
    </div>
  );
}
