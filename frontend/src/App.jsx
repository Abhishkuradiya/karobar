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
import CustomersPage from './pages/CustomersPage';
import BusinessSetupPage from './pages/BusinessSetupPage';
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
                <Route path="/sales" element={<SalesPage />} />
                <Route path="/sales/new" element={<SaleFormPage />} />
                <Route path="/sales/:saleId/edit" element={<SaleFormPage />} />
                <Route path="/customers" element={<CustomersPage />} />
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
