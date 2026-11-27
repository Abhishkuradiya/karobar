import { createContext, useContext, useState } from 'react';

const FeedbackContext = createContext(null);

export function FeedbackProvider({ children }) {
  const [request, setRequest] = useState(null);

  const confirm = (options) => new Promise((resolve) => {
    setRequest({
      title: options.title || 'Please confirm',
      message: options.message || 'Are you sure you want to continue?',
      confirmLabel: options.confirmLabel || 'Confirm',
      cancelLabel: options.cancelLabel || 'Cancel',
      tone: options.tone || 'danger',
      resolve,
    });
  });

  const close = (value) => {
    if (!request) return;
    request.resolve(value);
    setRequest(null);
  };

  return <FeedbackContext.Provider value={{ confirm }}>
    {children}
    {request && <div className="feedback-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && close(false)}>
      <section className="feedback-dialog" role="alertdialog" aria-modal="true" aria-labelledby="feedback-title" aria-describedby="feedback-message">
        <span className={`feedback-dialog-icon ${request.tone}`}><i className={request.tone === 'danger' ? 'bx bx-trash' : 'bx bx-info-circle'} /></span>
        <h2 id="feedback-title">{request.title}</h2>
        <p id="feedback-message">{request.message}</p>
        <div className="feedback-dialog-actions"><button type="button" className="secondary-button" onClick={() => close(false)}>{request.cancelLabel}</button><button type="button" className={`primary-button ${request.tone === 'danger' ? 'danger-button' : ''}`} onClick={() => close(true)}>{request.confirmLabel}</button></div>
      </section>
    </div>}
  </FeedbackContext.Provider>;
}

export function useFeedback() {
  const context = useContext(FeedbackContext);
  if (!context) throw new Error('useFeedback must be used inside FeedbackProvider');
  return context;
}
