import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';

// Expose React globally for debugging and external access
window.React = React;
window.ReactDOM = ReactDOM;

console.log('React app initializing...');
console.log('React version:', React.version);
console.log('ReactDOM version:', ReactDOM.version);

// Check if the target element exists
const targetElement = document.getElementById('assessor-app-root') || document.getElementById('root');
console.log('Target element found:', targetElement);

if (targetElement) {
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
