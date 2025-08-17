# 🚀 WordPress Backend Setup Guide

## Prerequisites
- XAMPP, WAMP, or similar local web server
- PHP 7.4+ and MySQL 5.7+

## Quick Setup

### 1. Start Your Web Server
- Start Apache and MySQL services
- Make sure ports 80 and 3306 are available

### 2. Access the Install Script
Open your browser and navigate to:
```
http://localhost/assessor-backend/install.php
```

### 3. Follow Installation Steps
- Enter database details (or use defaults)
- Wait for WordPress installation
- Wait for custom tables creation
- Wait for plugin activation

### 4. Default Login Credentials
- **Username**: `admin`
- **Password**: `admin123`

### 5. Test the API
Once installed, test the endpoint:
```
http://localhost/assessor-backend/wp-json/assessor/v1/login
```

## Manual Setup (Alternative)

### 1. Create Database
```sql
CREATE DATABASE assessor_db;
CREATE USER 'assessor_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON assessor_db.* TO 'assessor_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Configure wp-config.php
Copy `wp-config-sample.php` to `wp-config.php` and update:
```php
define('DB_NAME', 'assessor_db');
define('DB_USER', 'assessor_user');
define('DB_PASSWORD', 'your_password');
define('DB_HOST', 'localhost');
```

### 3. Activate Plugin
- Access WordPress admin: `http://localhost/assessor-backend/wp-admin`
- Go to Plugins > Installed Plugins
- Activate "Assessor History Archiving API"

## Troubleshooting

### API 404 Error
- Check if plugin is activated
- Verify .htaccess file exists
- Check WordPress permalink settings

### CORS Issues
- Plugin includes CORS headers
- Make sure frontend URL is correct
- Check browser console for errors

### Database Connection
- Verify MySQL service is running
- Check database credentials
- Ensure database exists

## File Structure
```
assessor-backend/
├── wp-content/
│   └── plugins/
│       └── assessor-api/
│           ├── assessor-api.php
│           └── includes/
├── install.php
└── wp-config.php
```

## Support
If you encounter issues, check:
1. Web server error logs
2. WordPress debug log
3. Browser console errors
4. Network tab in DevTools




