import React, { useState, useEffect } from 'react';
import { AuthContext } from './AuthContextValue';
import api from '../api/axios';
import { getErrorMessage } from '../utils/format';

export function AuthProvider({ children }) {
  const [token, setToken] = useState(() => localStorage.getItem('token') || null);
  const [user, setUser] = useState(() => {
    if (!localStorage.getItem('token')) return null;

    try {
      const stored = localStorage.getItem('user');
      return stored ? JSON.parse(stored) : null;
    } catch {
      return null;
    }
  });
  const [businesses, setBusinesses] = useState([]);
  const [activeBusiness, setActiveBusiness] = useState(null);
  const [businessError, setBusinessError] = useState('');
  const [loading, setLoading] = useState(Boolean(token));

  useEffect(() => {
    let active = true;

    if (!token) return () => { active = false; };

    const checkAuth = async () => {
      try {
        const userResponse = await api.get('/user');
        const businessResponse = await api.get('/businesses');
        if (!active) return;

        const currentUser = userResponse.data.user || userResponse.data;
        setUser(currentUser);
        localStorage.setItem('user', JSON.stringify(currentUser));
        setBusinesses(businessResponse.data.data);
        setActiveBusiness(businessResponse.data.active_business);
        setBusinessError('');
      } catch (error) {
        if (!active) return;
        if (error.response?.status === 401) {
          setUser(null);
          setToken(null);
          setBusinesses([]);
          setActiveBusiness(null);
          localStorage.removeItem('token');
          localStorage.removeItem('user');
        } else {
          setBusinessError(getErrorMessage(error, 'Could not load your businesses.'));
        }
      } finally {
        if (active) setLoading(false);
      }
    };

    checkAuth();
    return () => { active = false; };
  }, [token]);

  const establishSession = async (authData) => {
    setLoading(true);
    localStorage.setItem('token', authData.token);
    try {
      const businessResponse = await api.get('/businesses');
      setToken(authData.token);
      setUser(authData.user);
      localStorage.setItem('user', JSON.stringify(authData.user));
      setBusinesses(businessResponse.data.data);
      setActiveBusiness(businessResponse.data.active_business);
      setBusinessError('');
    } catch (error) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      setBusinessError(getErrorMessage(error, 'Could not load your businesses.'));
      throw error;
    } finally {
      setLoading(false);
    }
  };

  const login = async (loginInput, password) => {
    const res = await api.post('/login', {
      login: loginInput,
      password: password,
    });
    await establishSession(res.data);
    return res.data;
  };

  const register = async (userData) => {
    const res = await api.post('/register', userData);
    await establishSession(res.data);
    return res.data;
  };

  const refreshBusinesses = async () => {
    try {
      const response = await api.get('/businesses');
      setBusinesses(response.data.data);
      setActiveBusiness(response.data.active_business);
      setBusinessError('');
      return true;
    } catch (error) {
      setBusinessError(getErrorMessage(error, 'Could not load your businesses.'));
      return false;
    }
  };

  const createBusiness = async (businessData) => {
    const response = await api.post('/businesses', businessData);
    const business = response.data.business;
    setBusinesses((current) => (
      current.some((item) => item.id === business.id)
        ? current.map((item) => item.id === business.id ? business : item)
        : [...current, business]
    ));
    setActiveBusiness(business);
    setUser((current) => current ? { ...current, active_business_id: business.id } : current);
    setBusinessError('');

    return business;
  };

  const selectBusiness = async (businessId) => {
    const response = await api.patch('/businesses/active', { business_id: businessId });
    const business = response.data.active_business;
    setActiveBusiness(business);
    setUser((current) => current ? { ...current, active_business_id: business.id } : current);
    setBusinessError('');

    return business;
  };

  const updateBusinessProfile = async (profile) => {
    const response = await api.patch('/businesses/active/profile', profile);
    const business = response.data.business;
    setActiveBusiness(business);
    setBusinesses((current) => current.map((item) => item.id === business.id ? business : item));

    return business;
  };

  const updateProfile = async (profile) => {
    const response = await api.patch('/account/profile', profile);
    const updatedUser = response.data.user;
    setUser(updatedUser);
    localStorage.setItem('user', JSON.stringify(updatedUser));

    return updatedUser;
  };

  const logout = async () => {
    try {
      if (token) {
        await api.post('/logout');
      }
    } catch (err) {
      console.warn('Logout API error:', err);
    } finally {
      setToken(null);
      setUser(null);
      setBusinesses([]);
      setActiveBusiness(null);
      setBusinessError('');
      localStorage.removeItem('token');
      localStorage.removeItem('user');
    }
  };

  const value = {
    user,
    token,
    businesses,
    activeBusiness,
    businessError,
    isAuthenticated: Boolean(token),
    loading,
    login,
    register,
    createBusiness,
    selectBusiness,
    updateBusinessProfile,
    updateProfile,
    refreshBusinesses,
    logout,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
