import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext.jsx';
import ProtectedRoute from './components/ProtectedRoute';
import BusinessRequiredRoute from './components/BusinessRequiredRoute';
import Login from './pages/Login';
import Register from './pages/Register';
import Dashboard from './pages/Dashboard';
import AppLayout from './layouts/AppLayout';
import SalesPage from './pages/SalesPage';
import SaleFormPage from './pages/SaleFormPage';
import PurchasesPage from './pages/PurchasesPage';
import PurchaseFormPage from './pages/PurchaseFormPage';
import CustomersPage from './pages/CustomersPage';
import SuppliersPage from './pages/SuppliersPage';
import ExpensesPage from './pages/ExpensesPage';
import ScanBillsPage from './pages/ScanBillsPage';
import InventoryPage from './pages/InventoryPage';
import BankingPage from './pages/BankingPage';
import InvoicesPage from './pages/InvoicesPage';
import {
  ActivityPage,
  CashTallyPage,
  ChartOfAccountsPage,
  GstPage,
  ImportDataPage,
  JournalPage,
  MonthEndClosePage,
} from './pages/AccountingPages';
import BusinessSetupPage from './pages/BusinessSetupPage';
import SettingsPage from './pages/SettingsPage';
import HelpSupportPage from './pages/HelpSupportPage';
import ReportsPage from './pages/ReportsPage';
import OpeningBalancesPage from './pages/OpeningBalancesPage';
import AiAccountantPage from './pages/AiAccountantPage';
import './App.css';
import './business.css';

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route element={<ProtectedRoute />}>
            <Route path="/businesses/new" element={<BusinessSetupPage />} />
            <Route element={<BusinessRequiredRoute />}>
              <Route element={<AppLayout />}>
                <Route path="/dashboard" element={<Dashboard />} />
                <Route path="/assistant" element={<AiAccountantPage />} />
                <Route path="/sales" element={<SalesPage />} />
                <Route path="/sales/new" element={<SaleFormPage />} />
                <Route path="/sales/:saleId/edit" element={<SaleFormPage />} />
                <Route path="/customers" element={<CustomersPage />} />
                <Route path="/purchases" element={<PurchasesPage />} />
                <Route path="/purchases/new" element={<PurchaseFormPage />} />
                <Route path="/purchases/:purchaseId/edit" element={<PurchaseFormPage />} />
                <Route path="/suppliers" element={<SuppliersPage />} />
                <Route path="/expenses" element={<ExpensesPage />} />
                <Route path="/scan-bills" element={<ScanBillsPage />} />
                <Route path="/inventory" element={<InventoryPage />} />
                <Route path="/inventory/reorder" element={<InventoryPage initialTab="reorder" />} />
                <Route path="/inventory/expiry" element={<InventoryPage initialTab="expiring" />} />
                <Route path="/banking" element={<BankingPage />} />
                <Route path="/invoices" element={<InvoicesPage />} />
                <Route path="/gst" element={<GstPage />} />
                <Route path="/reports" element={<ReportsPage />} />
                <Route path="/reports/:reportId" element={<ReportsPage />} />
                <Route path="/cash-tally" element={<CashTallyPage />} />
                <Route path="/activity" element={<ActivityPage />} />
                <Route path="/month-end-close" element={<MonthEndClosePage />} />
                <Route path="/journal" element={<JournalPage />} />
                <Route path="/chart-of-accounts" element={<ChartOfAccountsPage />} />
                <Route path="/import-data" element={<ImportDataPage />} />
                <Route path="/opening-balances" element={<OpeningBalancesPage />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="/help-support" element={<HelpSupportPage />} />
              </Route>
            </Route>
          </Route>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
