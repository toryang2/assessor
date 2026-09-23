import React from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import App from './App';
import { apiService } from './utils/api';

// Expose React globally for debugging and external access
window.React = React;
window.ReactDOM = ReactDOM;

console.log('React app initializing...');
console.log('React version:', React.version);
console.log('ReactDOM version:', ReactDOM.version);

// Set favicon and apple-touch-icon dynamically (works in dev with npm start)
(function() {
  function setIcons(url) {
    if (!url) return;
    try {
      let icon = document.querySelector('link[rel="icon"]');
      if (!icon) { icon = document.createElement('link'); icon.rel = 'icon'; document.head.appendChild(icon); }
      icon.href = url;
      let apple = document.querySelector('link[rel="apple-touch-icon"]');
      if (!apple) { apple = document.createElement('link'); apple.rel = 'apple-touch-icon'; document.head.appendChild(apple); }
      apple.href = url;
    } catch (_) {}
  }
  try {
    if (window.__ASSESSOR_SETTINGS__ && window.__ASSESSOR_SETTINGS__.app_logo_url) {
      setIcons(window.__ASSESSOR_SETTINGS__.app_logo_url);
    } else {
      apiService.getBootstrapSettings().then((data) => {
        if (data && data.app_logo_url) setIcons(data.app_logo_url);
      }).catch(() => {});
    }
  } catch (_) {}
})();

// Check if the target element exists
const targetElement = document.getElementById('assessor-app-root') || document.getElementById('root');
console.log('Target element found:', targetElement);

if (targetElement) {
  // Remove WordPress loading screen if it exists
  const wpLoadingScreen = document.getElementById('wp-loading-screen');
  if (wpLoadingScreen) {
    wpLoadingScreen.remove();
    console.log('WordPress loading screen removed');
  }
  
  const root = ReactDOM.createRoot(targetElement);
  root.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
  console.log('React app rendered successfully!');
} else {
  console.error('Target element not found! Cannot render React app.');
}
