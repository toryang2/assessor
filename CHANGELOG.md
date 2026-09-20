# Changelog

All notable changes to this project will be documented in this file.

## [1.1.17] - 2026-09-20 — Security & Multi-Deployment Update

### 🔐 Security Improvements
- Hardened authentication and protected sensitive API endpoints.
- Removed the shared JWT fallback secret.
- JWT authentication now fails closed when a deployment has no configured signing key.
- Removed sensitive authentication/debug information from application logs.
- Protected system settings and administrative operations with proper authorization.

### 🏢 Multi-Deployment Support
- Added **Assessor Security Setup** for new WordPress installations.
- Automatically generates a unique cryptographic JWT signing key per deployment.
- Added a unique installation ID for each deployment.
- Added **Reinitialize as New Deployment** for cloned WordPress installations.
- Reinitialization generates a new installation identity and JWT key without affecting assessor records.
- Existing JWT sessions are automatically invalidated when a deployment is reinitialized.

### ⚙️ Login & Application Improvements
- Added a safe public `bootstrap-settings` endpoint for pre-login branding and application initialization.
- Prevented unauthenticated `/settings` requests during login and application startup.
- Preserved protected full settings access for authorized users.
- Improved compatibility across different user roles during application initialization.

### 🛡️ Deployment Safety
- JWT secrets are stored in `wp-config.php` rather than the database.
- Added a secure manual configuration fallback for environments where `wp-config.php` cannot be written automatically.
- Deployment-specific secrets are never exposed through the React frontend or REST API.

## [1.1.11] - 2026-08-24

### Changed
- Fixed navigation drawer title truncation by increasing drawer width slightly
- Fixed overlapping loading spinner in tables by putting it inside a clean elevated card, then removed it and adjusted loading logic to not block UI unnecessarily
- Fixed table row stretching on small result sets by removing height: 100% on tables

## [1.1.10] - 2026-08-20

### Changed
- Implement request management backend scripts and frontend modal with table components
- Establish application theme and initial component architecture with layout, data tables
- Minor fixes for the UI, request signatories, etc.
