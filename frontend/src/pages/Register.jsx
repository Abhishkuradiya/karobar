import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import SneatLogo from '../components/SneatLogo';

export default function Register() {
  const navigate = useNavigate();
  const { register, isAuthenticated } = useAuth();

  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [agreeTerms, setAgreeTerms] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [loading, setLoading] = useState(false);

  // If already authenticated, redirect
  if (isAuthenticated) {
    navigate('/dashboard', { replace: true });
  }

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrorMsg('');

    if (!username.trim()) {
      setErrorMsg('Please enter a username.');
      return;
    }
    if (!email.trim()) {
      setErrorMsg('Please enter your email address.');
      return;
    }
    if (!password || password.length < 6) {
      setErrorMsg('Password must be at least 6 characters long.');
      return;
    }
    if (!agreeTerms) {
      setErrorMsg('You must agree to the privacy policy & terms.');
      return;
    }

    try {
      setLoading(true);
      await register({
        username: username.trim(),
        email: email.trim(),
        password: password,
        terms: agreeTerms,
      });
      navigate('/dashboard', { replace: true });
    } catch (err) {
      if (err.response && err.response.data) {
        const data = err.response.data;
        if (data.errors) {
          // Flatten Laravel validation errors
          const firstError = Object.values(data.errors).flat()[0];
          setErrorMsg(firstError || 'Validation failed.');
        } else if (data.message) {
          setErrorMsg(data.message);
        } else {
          setErrorMsg('Registration failed. Please check your inputs.');
        }
      } else {
        setErrorMsg('Network error. Could not connect to backend.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="container-xxl">
      <div className="authentication-wrapper authentication-basic container-p-y">
        <div className="authentication-inner" style={{ maxWidth: '400px', margin: 'auto' }}>
          {/* Register Card */}
          <div className="card">
            <div className="card-body">
              {/* Logo */}
              <SneatLogo />

              <h4 className="mb-2">Adventure starts here 🚀</h4>
              <p className="mb-4">Make your app management easy and fun!</p>

              {errorMsg && (
                <div className="alert alert-danger py-2 px-3 mb-3 small" role="alert">
                  <i className="bx bx-error-circle me-1"></i> {errorMsg}
                </div>
              )}

              <form id="formAuthentication" className="mb-3" onSubmit={handleSubmit}>
                <div className="mb-3">
                  <label htmlFor="username" className="form-label">
                    Username
                  </label>
                  <input
                    type="text"
                    className="form-control"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    autoFocus
                    required
                  />
                </div>

                <div className="mb-3">
                  <label htmlFor="email" className="form-label">
                    Email
                  </label>
                  <input
                    type="email"
                    className="form-control"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                  />
                </div>

                <div className="mb-3 form-password-toggle">
                  <label className="form-label" htmlFor="password">
                    Password
                  </label>
                  <div className="input-group input-group-merge">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      id="password"
                      className="form-control"
                      name="password"
                      placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                    />
                    <span
                      className="input-group-text cursor-pointer"
                      onClick={() => setShowPassword(!showPassword)}
                    >
                      <i className={`bx ${showPassword ? 'bx-show' : 'bx-hide'}`}></i>
                    </span>
                  </div>
                  <small className="text-muted">Minimum 6 characters</small>
                </div>

                <div className="mb-3">
                  <div className="form-check">
                    <input
                      className="form-check-input"
                      type="checkbox"
                      id="terms-conditions"
                      name="terms"
                      checked={agreeTerms}
                      onChange={(e) => setAgreeTerms(e.target.checked)}
                      required
                    />
                    <label className="form-check-label" htmlFor="terms-conditions">
                      I agree to{' '}
                      <a href="javascript:void(0);">privacy policy &amp; terms</a>
                    </label>
                  </div>
                </div>

                <button
                  className="btn btn-primary d-grid w-100"
                  type="submit"
                  disabled={loading}
                >
                  {loading ? (
                    <span>
                      <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                      Creating account...
                    </span>
                  ) : (
                    'Sign up'
                  )}
                </button>
              </form>

              <p className="text-center">
                <span>Already have an account? </span>
                <Link to="/login">
                  <span>Sign in instead</span>
                </Link>
              </p>
            </div>
          </div>
          {/* /Register Card */}
        </div>
      </div>
    </div>
  );
}
