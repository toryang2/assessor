# Property Assessor History Archiving System

A comprehensive, production-ready history archiving application tailored for property assessors in Philippine local government. This system provides robust record management, search capabilities, and proper archiving features with a modern, responsive interface.

## 🌟 Features

### Core Functionality
- **Property Assessment Record Management** - Full CRUD operations for property records
- **Advanced Search & Filtering** - Search by Tax declaration number, owner, location, and date ranges
- **Historical Data Archiving** - Version tracking and audit trails for all changes
- **Document Attachment System** - Support for PDFs, images, and other file types
- **Export Capabilities** - CSV and JSON export for reports and data backup
- **User Authentication System** - Secure login for government personnel
- **Dashboard with Statistics** - Real-time monitoring and recent activity tracking
- **Responsive Design** - Optimized for desktop and tablet use
- **Smooth Animations** - Professional UI/UX with framer-motion animations

### Design Elements
- **Philippine Government Theme** - Deep blue (#1e3a8a) and gold accents
- **Professional Interface** - Clean typography and proper spacing
- **Data Tables** - Comprehensive tables with sorting and pagination
- **Modal Forms** - Animated forms for data entry with validation
- **Status Indicators** - Badges and indicators for record categorization
- **Breadcrumb Navigation** - Intuitive user flow and navigation
- **Accessible Design** - Following government web standards

## 🏗️ Architecture

### Frontend
- **React 18** - Modern React with hooks and functional components
- **Material-UI (MUI)** - Professional component library
- **Framer Motion** - Smooth animations and transitions
- **Theme UI/UX** - Custom Philippine government-inspired theme
- **Responsive Design** - Mobile-first approach with tablet optimization

### Backend
- **WordPress** - Headless CMS with custom REST API
- **Custom Tables** - Optimized database structure for assessor data
- **JWT Authentication** - Secure token-based authentication
- **File Management** - Secure document upload and storage
- **Audit Logging** - Comprehensive activity tracking

### Database Structure
- `wp_assessor_users` - Custom user management
- `wp_assessor_properties` - Property records
- `wp_assessor_property_versions` - Version history
- `wp_assessor_documents` - Document attachments
- `wp_assessor_audit_trail` - Audit logs

## 🚀 Installation

### Prerequisites
- WordPress 6.0+ with PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Node.js 16+ and npm
- Web server (Apache/Nginx)

### Backend Setup (WordPress)

1. **Install WordPress** in your web server directory
2. **Upload the Plugin**
   ```bash
   # Copy the assessor-backend folder to wp-content/plugins/
   cp -r assessor-backend/wp-content/plugins/assessor-api wp-content/plugins/
   ```
3. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Activate "Assessor History Archiving API"
4. **Configure Permalinks**
   - Go to Settings → Permalinks
   - Select "Post name" and save

### Frontend Setup (React)

1. **Navigate to Frontend Directory**
   ```bash
   cd assessor-frontend
   ```

2. **Install Dependencies**
   ```bash
   npm install
   ```

3. **Configure API Endpoint**
   ```bash
   # Create .env file
   echo "REACT_APP_API_URL=http://your-domain.com/wp-json/assessor/v1" > .env
   ```

4. **Start Development Server**
   ```bash
   npm start
   ```

5. **Build for Production**
   ```bash
   npm run build:prod
   ```

## 🔧 Configuration

### WordPress Configuration

The plugin automatically creates necessary database tables and a default admin user:

- **Username**: `admin`
- **Password**: `admin123`
- **Email**: `admin@localgov.ph`

**⚠️ Important**: Change the default password immediately after installation!

### Environment Variables

Create a `.env` file in the frontend directory:

```env
REACT_APP_API_URL=http://your-domain.com/wp-json/assessor/v1
REACT_APP_SITE_NAME=Property Assessor System
REACT_APP_VERSION=1.0.0
```
Create a `.env.production` file in the frontend directory:

```env
REACT_APP_API_URL=http://your-domain.com/wp-json/assessor/v1
REACT_APP_SITE_NAME=Property Assessor System
REACT_APP_VERSION=1.0.0
```

## 📱 Usage

### Login
1. Access the application at `/login`
2. Use your credentials to sign in
3. The system will redirect you to the dashboard

### Dashboard
- View key statistics and recent activity
- Access quick actions for common tasks
- Monitor system performance and usage

### Property Management
- **View Properties**: Browse all property records with search and filtering
- **Add Property**: Create new property assessment records
- **Edit Property**: Update existing records with change tracking
- **Delete Property**: Soft delete with audit trail

### Version History
- Track all changes to property records
- View detailed change history
- Restore previous versions if needed

### Document Management
- Upload supporting documents (PDF, images, etc.)
- Organize documents by property
- Secure file storage and access

### Export & Reports
- Export data in CSV or JSON format
- Generate custom reports
- Backup functionality for data preservation

### Audit Trail
- Monitor all system activities
- Track user actions and changes
- Maintain compliance and transparency

## 🔒 Security Features

- **JWT Authentication** - Secure token-based authentication
- **Role-based Access Control** - Admin and assessor roles
- **Input Sanitization** - Protection against malicious input
- **File Upload Validation** - Secure document handling
- **Audit Logging** - Complete activity tracking
- **HTTPS Support** - Encrypted data transmission

## 🎨 Customization

### Theme Customization
The system uses a custom theme with Philippine government colors:

```javascript
// src/theme/theme.js
const colors = {
  primary: {
    main: '#1e3a8a', // Deep blue
    light: '#3b82f6',
    dark: '#1e40af'
  },
  secondary: {
    main: '#f59e0b', // Gold
    light: '#fbbf24',
    dark: '#d97706'
  }
};
```

### Component Customization
All components are modular and can be easily customized:
- Modify component styles in the theme file
- Update animations in the animations object
- Customize API endpoints in the api.js file

## 📊 API Endpoints

### Authentication
- `POST /assessor/v1/login` - User authentication
- `POST /assessor/v1/logout` - User logout

### Properties
- `GET /assessor/v1/properties` - List properties with filtering
- `POST /assessor/v1/properties` - Create new property
- `GET /assessor/v1/properties/{id}` - Get property details
- `PUT /assessor/v1/properties/{id}` - Update property
- `DELETE /assessor/v1/properties/{id}` - Delete property

### Version History
- `GET /assessor/v1/properties/{id}/versions` - Get property versions

### Documents
- `GET /assessor/v1/properties/{id}/documents` - Get property documents
- `POST /assessor/v1/properties/{id}/documents` - Upload document

### Dashboard & Reports
- `GET /assessor/v1/dashboard` - Dashboard data
- `POST /assessor/v1/export` - Export data
- `GET /assessor/v1/audit` - Audit trail

## 🚨 Troubleshooting

### Common Issues

1. **Plugin Not Activating**
   - Check PHP version compatibility
   - Verify WordPress version
   - Check file permissions

2. **API Endpoints Not Working**
   - Verify permalink settings
   - Check .htaccess configuration
   - Ensure plugin is activated

3. **Database Tables Not Created**
   - Deactivate and reactivate the plugin
   - Check database user permissions
   - Verify MySQL version compatibility

4. **Frontend Build Issues**
   - Clear npm cache: `npm cache clean --force`
   - Delete node_modules and reinstall
   - Check Node.js version compatibility

### Debug Mode
Enable WordPress debug mode in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

For support and questions:
- Create an issue in the GitHub repository
- Contact the development team
- Check the documentation

## 🔄 Updates

### Version 1.0.0
- Initial release with core functionality
- Property management system
- Version history tracking
- Document management
- Audit trail system
- Dashboard and reporting
- User authentication and roles

## 📞 Contact

**Philippine Local Government**
- Email: admin@localgov.ph
- Website: https://localgov.ph
- Support: support@localgov.ph

---

**Built with ❤️ for Philippine Local Government**




