import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import api from '../api/axios';

export default function Dashboard() {
  const { user, logout } = useAuth();
  const [apiResponse, setApiResponse] = useState(null);
  const [testingApi, setTestingApi] = useState(false);

  const handleTestApi = async () => {
    try {
      setTestingApi(true);
      const res = await api.get('/user');
      setApiResponse(res.data);
    } catch (err) {
      setApiResponse({ error: err.message, response: err.response?.data });
    } finally {
      setTestingApi(false);
    }
  };

  return (
    <div className="layout-wrapper layout-content-navbar">
      <div className="layout-container">
        {/* Main Content Area */}
        <div className="layout-page" style={{ paddingLeft: 0 }}>
          {/* Top Navbar */}
          <nav
            className="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme my-3"
            id="layout-navbar"
          >
            <div className="navbar-nav-right d-flex align-items-center w-100 justify-content-between" id="navbar-collapse">
              <div className="d-flex align-items-center gap-2">
                <span className="app-brand-text demo text-primary fw-bolder fs-4">KarobarV2</span>
                <span className="badge bg-label-primary ms-2">Sneat + Laravel + React</span>
              </div>

              <div className="d-flex align-items-center gap-3">
                <div className="d-flex align-items-center gap-2">
                  <div className="avatar avatar-online">
                    <span className="avatar-initial rounded-circle bg-label-primary fw-bold">
                      {user?.username ? user.username.charAt(0).toUpperCase() : 'U'}
                    </span>
                  </div>
                  <div className="d-none d-sm-block text-start">
                    <span className="fw-semibold d-block text-dark">{user?.username || 'User'}</span>
                    <small className="text-muted">{user?.email}</small>
                  </div>
                </div>

                <button onClick={logout} className="btn btn-outline-danger btn-sm">
                  <i className="bx bx-power-off me-1"></i> Log Out
                </button>
              </div>
            </div>
          </nav>

          {/* Content container */}
          <div className="content-wrapper">
            <div className="container-xxl flex-grow-1 container-p-y">
              {/* Row 1: Welcome & Status */}
              <div className="row">
                <div className="col-lg-8 mb-4 order-0">
                  <div className="card">
                    <div className="d-flex align-items-end row">
                      <div className="col-sm-7">
                        <div className="card-body">
                          <h5 className="card-title text-primary">
                            Congratulations {user?.username}! 🎉
                          </h5>
                          <p className="mb-4">
                            You have successfully authenticated via <span className="fw-bold">Laravel Sanctum</span> &amp; <span className="fw-bold">React SPA</span> with MySQL database.
                          </p>
                          <button onClick={handleTestApi} className="btn btn-sm btn-outline-primary" disabled={testingApi}>
                            {testingApi ? 'Testing API...' : 'Test Auth API (/api/user)'}
                          </button>
                        </div>
                      </div>
                      <div className="col-sm-5 text-center text-sm-left">
                        <div className="card-body pb-0 px-0 px-md-4">
                          <img
                            src="/assets/img/illustrations/man-with-laptop-light.png"
                            height="140"
                            alt="View Badge User"
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                {/* System Specs Card */}
                <div className="col-lg-4 col-md-4 order-1 mb-4">
                  <div className="card h-100">
                    <div className="card-body">
                      <div className="card-title d-flex align-items-start justify-content-between">
                        <div className="avatar flex-shrink-0">
                          <span className="badge bg-label-success p-2 rounded">
                            <i className="bx bx-check-shield fs-4"></i>
                          </span>
                        </div>
                        <span className="badge bg-success">Authenticated</span>
                      </div>
                      <span className="fw-semibold d-block mb-1">Stack Status</span>
                      <h4 className="card-title mb-2">Connected</h4>
                      <div className="small text-muted mt-2">
                        <div><i className="bx bx-server me-1 text-primary"></i> Backend: Laravel (Sanctum)</div>
                        <div><i className="bx bxl-react me-1 text-info"></i> Frontend: React (Vite)</div>
                        <div><i className="bx bx-data me-1 text-warning"></i> Database: MySQL (karobar_v2)</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Row 2: User Account Profile Details */}
              <div className="row">
                <div className="col-md-6 mb-4">
                  <div className="card">
                    <h5 className="card-header d-flex justify-content-between align-items-center">
                      <span>User Profile Information</span>
                      <span className="badge bg-label-info">Active Session</span>
                    </h5>
                    <div className="card-body">
                      <ul className="list-group list-group-flush">
                        <li className="list-group-item d-flex justify-content-between px-0">
                          <span className="text-muted">User ID:</span>
                          <span className="fw-bold">#{user?.id}</span>
                        </li>
                        <li className="list-group-item d-flex justify-content-between px-0">
                          <span className="text-muted">Username:</span>
                          <span className="fw-bold">{user?.username}</span>
                        </li>
                        <li className="list-group-item d-flex justify-content-between px-0">
                          <span className="text-muted">Email:</span>
                          <span className="fw-bold">{user?.email}</span>
                        </li>
                        <li className="list-group-item d-flex justify-content-between px-0">
                          <span className="text-muted">Created At:</span>
                          <span className="text-muted small">
                            {user?.created_at ? new Date(user.created_at).toLocaleString() : 'Just now'}
                          </span>
                        </li>
                      </ul>
                    </div>
                  </div>
                </div>

                {/* API Response Display Card */}
                <div className="col-md-6 mb-4">
                  <div className="card h-100">
                    <h5 className="card-header d-flex justify-content-between align-items-center">
                      <span>Live API Response</span>
                      <span className="badge bg-label-secondary">JSON</span>
                    </h5>
                    <div className="card-body">
                      {apiResponse ? (
                        <pre className="bg-light p-3 rounded small text-dark mb-0" style={{ maxHeight: '200px', overflowY: 'auto' }}>
                          {JSON.stringify(apiResponse, null, 2)}
                        </pre>
                      ) : (
                        <div className="text-center text-muted py-4">
                          <i className="bx bx-code-alt fs-2 mb-2 d-block"></i>
                          Click "Test Auth API" above to inspect live backend response.
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
