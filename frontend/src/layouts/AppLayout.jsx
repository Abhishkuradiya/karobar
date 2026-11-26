import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/useAuth';
import SneatLogo from '../components/SneatLogo';
import api from '../api/axios';
import { getErrorMessage } from '../utils/format';

const navigation = [
  { label: 'Dashboard', icon: 'bx-grid-alt', to: '/dashboard' },
  { label: 'AI Accountant', icon: 'bx-bot', to: '/assistant' },
  { label: 'Sales', icon: 'bx-shopping-bag', to: '/sales' },
  { label: 'Purchases', icon: 'bx-cart', to: '/purchases' },
  { label: 'Scan Bills', icon: 'bx-scan', to: '/scan-bills' },
  { label: 'Expenses', icon: 'bx-receipt', to: '/expenses' },
  { label: 'Customers', icon: 'bx-group', to: '/customers' },
  { label: 'Suppliers', icon: 'bx-store-alt', to: '/suppliers' },
  { label: 'Inventory', icon: 'bx-cube', to: '/inventory' },
  { label: 'Reorder list', icon: 'bx-cart', to: '/inventory/reorder' },
  { label: 'Expiring stock', icon: 'bx-calendar-exclamation', to: '/inventory/expiry' },
  { label: 'Banking', icon: 'bx-building-house', to: '/banking' },
  { label: 'Invoices', icon: 'bx-file', to: '/invoices' },
  { label: 'GST', icon: 'bx-calculator', to: '/gst' },
  { label: 'Reports', icon: 'bx-bar-chart-alt-2', to: '/reports' },
  { label: 'Business check-up', icon: 'bx-pulse', to: '/reports/business-checkup' },
  { label: 'Cash forecast', icon: 'bx-trending-up', to: '/reports/cash-forecast' },
  { label: 'Profit by product', icon: 'bx-purchase-tag', to: '/reports/profit-by-product' },
  { label: 'Cash Tally', icon: 'bx-money', to: '/cash-tally' },
  { label: 'Activity', icon: 'bx-time-five', to: '/activity' },
  { label: 'Month-end Close', icon: 'bx-check-shield', to: '/month-end-close' },
  { label: 'Journal', icon: 'bx-notepad', to: '/journal' },
  { label: 'Chart of Accounts', icon: 'bx-list-ul', to: '/chart-of-accounts' },
  { label: 'Opening balances', icon: 'bx-calculator', to: '/opening-balances' },
  { label: 'Import Data', icon: 'bx-import', to: '/import-data' },
  { label: 'Settings', icon: 'bx-cog', to: '/settings' },
  { label: 'Help & support', icon: 'bx-help-circle', to: '/help-support' },
];

export default function AppLayout() {
  const { user, businesses, activeBusiness, selectBusiness, logout } = useAuth();
  const navigate = useNavigate();
  const notificationMenuRef = useRef(null);
  const profileMenuRef = useRef(null);
  const businessMenuRef = useRef(null);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [businessMenuOpen, setBusinessMenuOpen] = useState(false);
  const [switchingId, setSwitchingId] = useState(null);
  const [switchError, setSwitchError] = useState('');
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [notificationsLoading, setNotificationsLoading] = useState(false);
  const [notificationError, setNotificationError] = useState('');
  const [profileMenuOpen, setProfileMenuOpen] = useState(false);
  const userLabel = user?.name || user?.username || 'Account';
  const initials = userLabel.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
  const loadNotifications = async (showError = false) => {
    if (showError) {
      setNotificationsLoading(true);
      setNotificationError('');
    }
    try {
      const response = await api.get('/notifications');
      setNotifications(response.data.data);
      setUnreadCount(response.data.unread_count);
      setNotificationError('');
    } catch (requestError) {
      setNotificationError(getErrorMessage(requestError, 'Could not load notifications.'));
    } finally {
      if (showError) setNotificationsLoading(false);
    }
  };

  useEffect(() => {
    let active = true;
    const refresh = () => {
      api.get('/notifications')
        .then((response) => {
          if (!active) return;
          setNotifications(response.data.data);
          setUnreadCount(response.data.unread_count);
          setNotificationError('');
        })
        .catch((requestError) => {
          if (active) setNotificationError(getErrorMessage(requestError, 'Could not load notifications.'));
        });
    };
    refresh();
    const timer = window.setInterval(refresh, 60_000);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [activeBusiness?.id]);

  useEffect(() => {
    if (!notificationsOpen && !profileMenuOpen && !businessMenuOpen) return undefined;
    const closeOnOutsideClick = (event) => {
      if (!notificationMenuRef.current?.contains(event.target)) setNotificationsOpen(false);
      if (!profileMenuRef.current?.contains(event.target)) setProfileMenuOpen(false);
      if (!businessMenuRef.current?.contains(event.target)) setBusinessMenuOpen(false);
    };
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') {
        setNotificationsOpen(false);
        setProfileMenuOpen(false);
        setBusinessMenuOpen(false);
      }
    };
    document.addEventListener('pointerdown', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsideClick);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [businessMenuOpen, notificationsOpen, profileMenuOpen]);

  const openNotification = async (notification) => {
    setNotificationError('');
    try {
      await api.post('/notifications/read', { notification_key: notification.key });
      setNotifications((current) => current.map((item) => item.key === notification.key ? { ...item, is_read: true } : item));
      setUnreadCount((current) => Math.max(0, current - (notification.is_read ? 0 : 1)));
      setNotificationsOpen(false);
      navigate(notification.url);
    } catch (requestError) {
      setNotificationError(getErrorMessage(requestError, 'Could not mark this notification as read.'));
    }
  };

  const markAllNotificationsRead = async () => {
    setNotificationError('');
    try {
      await api.post('/notifications/read-all');
      setNotifications((current) => current.map((item) => ({ ...item, is_read: true })));
      setUnreadCount(0);
    } catch (requestError) {
      setNotificationError(getErrorMessage(requestError, 'Could not mark notifications as read.'));
    }
  };

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
              end={['/dashboard', '/inventory', '/reports'].includes(item.to)}
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
          <div className="business-switcher-wrap" ref={businessMenuRef}>
            <button
              type="button"
              className="business-switcher-button"
              aria-haspopup="menu"
              aria-expanded={businessMenuOpen}
              onClick={() => {
                setSwitchError('');
                setBusinessMenuOpen((open) => {
                  const nextOpen = !open;
                  if (nextOpen) {
                    setNotificationsOpen(false);
                    setProfileMenuOpen(false);
                  }
                  return nextOpen;
                });
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
            <div className="notification-menu-wrap" ref={notificationMenuRef}>
              <button
                type="button"
                className="icon-button notification-button"
                aria-label={`Notifications${unreadCount ? `, ${unreadCount} unread` : ''}`}
                aria-expanded={notificationsOpen}
                aria-haspopup="dialog"
                onClick={() => {
                  const opening = !notificationsOpen;
                  if (opening) {
                    setBusinessMenuOpen(false);
                    setProfileMenuOpen(false);
                  }
                  setNotificationsOpen(opening);
                  if (opening) loadNotifications(true);
                }}
              >
                <i className="bx bx-bell" />
                {unreadCount > 0 && <span className="notification-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>}
              </button>
              {notificationsOpen && (
                <section className="notification-popover" role="dialog" aria-label="Notifications">
                  <header className="notification-popover-header">
                    <div><strong>Notifications</strong><small>{unreadCount ? `${unreadCount} unread` : 'You’re all caught up'}</small></div>
                    <button type="button" className="notification-mark-all" disabled={!unreadCount} onClick={markAllNotificationsRead}>Mark all read</button>
                  </header>
                  {notificationError && <p className="notification-error" role="alert">{notificationError}</p>}
                  {notificationsLoading ? <div className="notification-empty">Loading notifications…</div>
                    : notifications.length === 0 ? <div className="notification-empty"><i className="bx bx-check-circle" /><span>No new business alerts.</span></div>
                      : <div className="notification-list">{notifications.map((notification) => (
                        <button
                          type="button"
                          className={`notification-item${notification.is_read ? '' : ' is-unread'}`}
                          key={notification.key}
                          onClick={() => openNotification(notification)}
                        >
                          <span className={`notification-item-icon category-${notification.category}`}><i className={`bx ${notification.category === 'low_stock' || notification.category === 'expiring_inventory' ? 'bx-package' : 'bx-receipt'}`} /></span>
                          <span className="notification-item-copy">
                            <strong>{notification.title}</strong>
                            <small>{notification.message}</small>
                            <time>{notification.created_at?.slice(0, 10)}</time>
                          </span>
                          {!notification.is_read && <span className="notification-unread-dot" aria-label="Unread" />}
                        </button>
                      ))}</div>}
                  <Link className="notification-settings-link" to="/settings?tab=notifications" onClick={() => setNotificationsOpen(false)}>Manage notification preferences<i className="bx bx-right-arrow-alt" /></Link>
                </section>
              )}
            </div>
            <div className="profile-menu-wrap" ref={profileMenuRef}>
              <button
                type="button"
                className={`profile-trigger${profileMenuOpen ? ' is-open' : ''}`}
                title={userLabel}
                aria-label="Open profile menu"
                aria-haspopup="menu"
                aria-expanded={profileMenuOpen}
                onClick={() => {
                  const opening = !profileMenuOpen;
                  if (opening) {
                    setBusinessMenuOpen(false);
                    setNotificationsOpen(false);
                  }
                  setProfileMenuOpen(opening);
                }}
              >
                <span className="user-avatar">{initials || 'U'}</span>
                <span className="profile-trigger-copy"><strong>{userLabel}</strong><small>Account</small></span>
                <i className={`bx ${profileMenuOpen ? 'bx-chevron-up' : 'bx-chevron-down'}`} />
              </button>
              {profileMenuOpen && (
                <div className="profile-menu" role="menu" aria-label="Profile options">
                  <div className="profile-menu-heading"><span className="user-avatar">{initials || 'U'}</span><span><strong>{userLabel}</strong><small>{activeBusiness?.name || 'Your account'}</small></span></div>
                  <Link to="/settings?tab=account" role="menuitem" onClick={() => setProfileMenuOpen(false)}><i className="bx bx-user" /> Account settings</Link>
                  <Link to="/settings?tab=notifications" role="menuitem" onClick={() => setProfileMenuOpen(false)}><i className="bx bx-bell" /> Notification preferences</Link>
                  <button type="button" className="profile-menu-logout" role="menuitem" onClick={() => { setProfileMenuOpen(false); logout(); }}><i className="bx bx-log-out" /> Log out</button>
                </div>
              )}
            </div>
          </div>
        </header>

        <main className="business-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
