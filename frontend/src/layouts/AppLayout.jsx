import { useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/useAuth';
import SneatLogo from '../components/SneatLogo';
import { getErrorMessage } from '../utils/format';

const navigation = [
  { label: 'Dashboard', icon: 'bx-grid-alt', to: '/dashboard' },
  { label: 'AI Accountant', icon: 'bx-bot', disabled: true },
  { label: 'Sales', icon: 'bx-shopping-bag', to: '/sales' },
  { label: 'Purchases', icon: 'bx-cart', disabled: true },
  { label: 'Scan Bills', icon: 'bx-scan', disabled: true },
  { label: 'Expenses', icon: 'bx-receipt', disabled: true },
  { label: 'Customers', icon: 'bx-group', to: '/customers' },
  { label: 'Suppliers', icon: 'bx-package', disabled: true },
  { label: 'Inventory', icon: 'bx-cube', disabled: true },
  { label: 'Banking', icon: 'bx-building-house', disabled: true },
  { label: 'Invoices', icon: 'bx-file', disabled: true },
  { label: 'GST', icon: 'bx-percent', disabled: true },
];

export default function AppLayout() {
  const { user, businesses, activeBusiness, selectBusiness, logout } = useAuth();
  const navigate = useNavigate();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [businessMenuOpen, setBusinessMenuOpen] = useState(false);
  const [switchingId, setSwitchingId] = useState(null);
  const [switchError, setSwitchError] = useState('');
  const userLabel = user?.name || user?.username || 'Account';
  const initials = userLabel.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
  const handleBusinessSwitch = async (business) => {
    if (business.id === activeBusiness?.id) {
      setBusinessMenuOpen(false);
      return;
    }

    setSwitchingId(business.id);
    setSwitchError('');
    try {
      await selectBusiness(business.id);
      setBusinessMenuOpen(false);
      navigate('/dashboard', { replace: true });
    } catch (requestError) {
      setSwitchError(getErrorMessage(requestError, 'Could not switch businesses.'));
    } finally {
      setSwitchingId(null);
    }
  };

  return (
    <div className="business-shell">
      {sidebarOpen && <button className="mobile-sidebar-backdrop" onClick={() => setSidebarOpen(false)} aria-label="Close navigation" />}

      <aside className={`business-sidebar ${sidebarOpen ? 'is-open' : ''}`}>
        <div className="business-brand">
          <SneatLogo title="Karobar" />
          <span className="business-tagline">Daily books, kept right.</span>
        </div>

        <nav className="business-nav" aria-label="Main navigation">
          {navigation.map((item) => item.disabled ? (
            <span className="business-nav-link is-disabled" key={item.label} aria-disabled="true" title="Coming soon">
              <i className={`bx ${item.icon}`} />
              <span>{item.label}</span>
              <small>Soon</small>
            </span>
          ) : (
            <NavLink
              className={({ isActive }) => `business-nav-link${isActive ? ' is-active' : ''}`}
              end={item.to === '/dashboard'}
              key={item.label}
              onClick={() => setSidebarOpen(false)}
              to={item.to}
            >
              <i className={`bx ${item.icon}`} />
              <span>{item.label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-bottom">
          <span className="sidebar-bottom-icon"><i className="bx bx-shield-quarter" /></span>
          <div><strong>Your books, in order</strong><small>Private to your account</small></div>
        </div>
      </aside>

      <div className="business-main">
        <header className="business-topbar">
          <button type="button" className="icon-button mobile-menu-button" onClick={() => setSidebarOpen(true)} aria-label="Open navigation">
            <i className="bx bx-menu" />
          </button>
          <div className="business-switcher-wrap">
            <button
              type="button"
              className="business-switcher-button"
              aria-haspopup="menu"
              aria-expanded={businessMenuOpen}
              onClick={() => {
                setSwitchError('');
                setBusinessMenuOpen((open) => !open);
              }}
            >
              <span className="business-switcher-copy">
                <strong>{activeBusiness?.name}</strong>
                <small>Owner</small>
              </span>
              <i className={`bx ${businessMenuOpen ? 'bx-chevron-up' : 'bx-chevron-down'}`} />
            </button>
            {businessMenuOpen && (
              <div className="business-switcher-menu" role="menu" aria-label="Select business">
                <span className="business-switcher-heading">Your businesses</span>
                {businesses.map((business) => (
                  <button
                    type="button"
                    key={business.id}
                    className={`business-picker-item${business.id === activeBusiness?.id ? ' is-active' : ''}`}
                    role="menuitem"
                    disabled={switchingId !== null}
                    onClick={() => handleBusinessSwitch(business)}
                  >
                    <span className="business-picker-avatar">
                      {business.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()}
                    </span>
                    <span className="business-picker-copy">
                      <strong>{business.name}</strong>
                      <small>{switchingId === business.id ? 'Switching…' : 'Owner'}</small>
                    </span>
                    {business.id === activeBusiness?.id && <i className="bx bx-check business-picker-check" />}
                  </button>
                ))}
                {switchError && <p className="business-switcher-error" role="alert">{switchError}</p>}
                <Link
                  className="business-picker-add"
                  role="menuitem"
                  to="/businesses/new"
                  onClick={() => setBusinessMenuOpen(false)}
                >
                  <i className="bx bx-plus" /> Add a business
                </Link>
              </div>
            )}
          </div>
          <div className="topbar-actions">
            <button type="button" className="icon-button notification-button" aria-label="Notifications">
              <i className="bx bx-bell" />
            </button>
            <div className="user-avatar" title={userLabel}>{initials || 'U'}</div>
            <button type="button" className="topbar-logout" onClick={logout}>Log out</button>
          </div>
        </header>

        <main className="business-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
