# Property Assessor System

A modern property assessment management system built for Philippine local government units. Features comprehensive record management, audit trails, and document handling with a responsive web interface.

## ✨ Features

- **Property Management** - Create, edit, and manage property assessment records
- **Search & Filter** - Advanced search by tax declaration, owner, location, and dates
- **Version History** - Track all changes with complete audit trails
- **Document Management** - Upload and organize supporting documents
- **User Authentication** - Secure role-based access control
- **Export & Reports** - Generate CSV/JSON exports and custom reports
- **Responsive Design** - Works on desktop and tablet devices

## 🚀 Quick Start

### Prerequisites

- WordPress 6.0+ with PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Node.js 16+ and npm

### Installation

1. **Setup WordPress Backend**
   ```bash
   # Copy plugin to WordPress
   cp -r assessor-backend/wp-content/plugins/assessor-api wp-content/plugins/
   
   # Activate plugin in WordPress Admin → Plugins
   # Set permalinks to "Post name" in Settings → Permalinks
   ```

2. **Setup React Frontend**
   ```bash
   cd assessor-frontend
   npm install
   
   # Create environment file
   echo "REACT_APP_API_URL=http://your-domain.com/wp-json/assessor/v1" > .env
   
   # Start development server
   npm start
   
   # Or build for production
   npm run build:prod
   ```

3. **Setup WordPress Theme (Optional)**
   ```bash
   # Copy theme to WordPress
   cp -r assessor-theme wp-content/themes/
   
   # Activate theme in WordPress Admin → Appearance → Themes
   ```

## 🔐 Default Login

After installation, use these credentials:

- **Username**: `admin`
- **Password**: `admin123`
- **Email**: `admin@localgov.ph`

⚠️ **Important**: Change the default password immediately!

## 📁 Project Structure

```
assessor/
├── assessor-backend/          # WordPress plugin
│   └── wp-content/plugins/assessor-api/
├── assessor-frontend/         # React application
│   ├── src/
│   ├── public/
│   └── package.json
├── assessor-theme/           # WordPress theme (optional)
└── README.md
```

## 🛠️ Configuration

### Environment Variables

Create `.env` in the frontend directory:

```env
REACT_APP_API_URL=http://your-domain.com/wp-json/assessor/v1
REACT_APP_SITE_NAME=Assessor Archiving System
```

### WordPress Settings

The plugin automatically creates:
- Database tables for properties, versions, documents, and audit logs
- Default admin user with full access
- REST API endpoints for frontend communication

## 📖 Usage

1. **Login** - Access the system at `/login`
2. **Dashboard** - View statistics and recent activity
3. **Properties** - Manage property assessment records
4. **Documents** - Upload and organize supporting files
5. **Audit Trail** - Monitor all system activities
6. **Export** - Generate reports and data backups

## 🔧 Development

### Frontend Development
```bash
cd assessor-frontend
npm start          # Development server
npm run build:prod # Production build
npm test           # Run tests
```

### Backend Development
- Edit PHP files in `assessor-backend/wp-content/plugins/assessor-api/`
- Database tables are auto-created on plugin activation
- API endpoints follow WordPress REST API standards

## 🚨 Troubleshooting

**Plugin not working?**
- Check WordPress and PHP versions
- Verify permalinks are set to "Post name"
- Ensure plugin is activated

**Frontend build issues?**
- Clear npm cache: `npm cache clean --force`
- Delete `node_modules` and run `npm install`
- Check Node.js version compatibility

**API errors?**
- Verify WordPress REST API is enabled
- Check CORS settings if accessing from different domain
- Enable WordPress debug mode for detailed errors

## 🤝 Support

For issues and questions:
- Check the troubleshooting section above
- Review WordPress and React documentation
- Contact your system administrator

## 📄 License

MIT License - Built for Assessor - LGU

---

**🇵🇭 Built with ❤️ for Local Government Units**