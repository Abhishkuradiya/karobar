import { useState } from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/useAuth';

export default function BusinessRequiredRoute() {
  const { businesses, activeBusiness, businessError, refreshBusinesses } = useAuth();
  const [retrying, setRetrying] = useState(false);

  if (businessError) {
    return (
      <main className="business-state-screen">
        <div className="business-state-card">
          <span className="summary-icon summary-icon-amber"><i className="bx bx-error-circle" /></span>
          <h1>We couldn't load your businesses</h1>
          <p>{businessError}</p>
          <button
            type="button"
            className="btn btn-primary"
            disabled={retrying}
            onClick={async () => {
              setRetrying(true);
              await refreshBusinesses();
              setRetrying(false);
            }}
          >
            {retrying ? 'Retrying…' : 'Try again'}
          </button>
        </div>
      </main>
    );
  }

  if (businesses.length === 0) {
    return <Navigate to="/businesses/new" replace />;
  }

  if (!activeBusiness) {
    return (
      <main className="business-state-screen">
        <div className="business-state-card">
          <span className="spinner-border spinner-border-sm text-primary" />
          <p>Loading your selected business…</p>
        </div>
      </main>
    );
  }

  return <Outlet />;
}
