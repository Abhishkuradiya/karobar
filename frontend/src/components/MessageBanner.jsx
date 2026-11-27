import { useEffect, useState } from 'react';

export default function MessageBanner({ message, type = 'success', onDismiss, duration = 5000 }) {
  const [visible, setVisible] = useState(Boolean(message));

  useEffect(() => {
    setVisible(Boolean(message));
    if (!message) return undefined;
    const timer = window.setTimeout(() => setVisible(false), duration);
    return () => window.clearTimeout(timer);
  }, [message, duration]);

  if (!message || !visible) return null;
  const dismiss = () => {
    setVisible(false);
    onDismiss?.();
  };
  const isError = type === 'error';
  const isWarning = type === 'warning';
  return <div className={`message-banner message-banner-${isError ? 'error' : isWarning ? 'warning' : 'success'}`} role={isError ? 'alert' : 'status'}>
    <span className="message-banner-icon"><i className={`bx ${isError ? 'bx-error-circle' : isWarning ? 'bx-info-circle' : 'bx-check-circle'}`} /></span>
    <span className="message-banner-text">{message}</span>
    <button type="button" className="message-banner-close" aria-label="Dismiss message" onClick={dismiss}><i className="bx bx-x" /></button>
  </div>;
}
