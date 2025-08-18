# Assessor API Setup Instructions

## Database Migration (Required for New Field Structure)

**IMPORTANT**: Before using the updated system, you must migrate your database to the new field structure.

### Step 1: Deactivate the Plugin
1. Go to WordPress Admin → Plugins
2. Deactivate the "Assessor History Archiving API" plugin

### Step 2: Run Database Migration
1. Access your MySQL database (via phpMyAdmin or command line)
2. Run the SQL script from `reactivate_plugin.sql`
3. This will drop and recreate the properties tables with the new schema

### Step 3: Reactivate the Plugin
1. Go to WordPress Admin → Plugins
2. Activate the "Assessor History Archiving API" plugin

## New Field Structure

The system now uses the following field structure for properties:

### Basic Information
- **Tax Declaration Number** (Required) - Unique identifier for the property
- **Previous Tax Declaration Number** - Links to the previous declaration in the chain
- **Declarant** - Split into Last Name, First Name, and Middle Initial
- **Location** - Property location (Required)
- **Lot Number** - Property lot number
- **Unique Lot Number Identified** - Additional lot identifier
- **Area (hectare)** - Property area in hectares
- **Title Number** - Property title number
- **Assessed Value** - Property assessed value in pesos
- **Effectivity Date** - When the assessment takes effect
- **PIN** - Property Identification Number (text field)
- **Address** - Complete property address
- **Assessment Date** - Date of assessment
- **Kind of Property** - Land, Building, Machinery, Improvements, Plant/Trees (Required)
- **General Class** - Residential, Commercial, Industrial, etc.
- **Memoranda** - Additional notes
- **Supporting Documents** - Document references

## Tax Declaration Linking Feature

The system now supports manual linking of tax declarations to create historical chains:

### How It Works
1. **Manual Linking**: Enter the previous tax declaration number in the "Previous Tax Declaration Number" field when creating a new property.

2. **Foreign Key Relationship**: The system will only create a link if the referenced previous tax declaration number exists in the database.

3. **History Chain**: Click on any tax declaration number in the table to view the complete historical chain, showing how properties evolved over time.

### Example Chain
```
22-010-0002-12345 → 10-0002-12345 → G-00123
```
- **22-010-0002-12345** (Current/Newest) - has previous_tax_declaration_number = "10-0002-12345"
- **10-0002-12345** (Previous) - has previous_tax_declaration_number = "G-00123"
- **G-00123** (Oldest) - has no previous_tax_declaration_number

### Features
- **Manual Control**: You control which properties are linked by entering the previous tax declaration number
- **Data Integrity**: Links are only created if the referenced property exists in the database
- **Visual Chain Display**: The history modal shows the complete chain with property details
- **Clickable Links**: Both current and previous tax declaration numbers are clickable
- **Property Details**: Each entry in the chain shows declarant, location, assessed value, and property type
- **Chronological Order**: History is displayed from newest to oldest

## Installation Steps

1. Upload the plugin files to `/wp-content/plugins/assessor-api/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically create the required database tables

## Configuration

### Environment Variables
Set the following in your WordPress configuration:

```php
// JWT Secret (change this to a secure random string)
define('JWT_SECRET', 'your-secure-jwt-secret-here');

// API Base URL
define('ASSESSOR_API_BASE_URL', 'http://your-domain.com/wp-json/assessor/v1');
```

### Default Admin User
The plugin creates a default admin user:
- **Username**: `admin`
- **Password**: `admin123`
- **Email**: `admin@localgov.ph`

**⚠️ Important**: Change the default password immediately after installation!

## API Endpoints

### Authentication
- `POST /assessor/v1/login` - User authentication
- `POST /assessor/v1/logout` - User logout
- `GET /assessor/v1/validate-token` - Validate JWT token

### Properties
- `GET /assessor/v1/properties` - List properties with filtering
- `POST /assessor/v1/properties` - Create new property
- `GET /assessor/v1/properties/{id}` - Get property details
- `PUT /assessor/v1/properties/{id}` - Update property
- `DELETE /assessor/v1/properties/{id}` - Delete property

### Dashboard & Reports
- `GET /assessor/v1/dashboard` - Dashboard data
- `POST /assessor/v1/export` - Export data
- `GET /assessor/v1/audit` - Audit trail

## Troubleshooting

### Common Issues

1. **Plugin Not Activating**
   - Check PHP version compatibility (requires PHP 7.4+)
   - Verify WordPress version (requires 5.0+)
   - Check file permissions

2. **API Endpoints Not Working**
   - Verify permalink settings are set to "Post name"
   - Check .htaccess configuration
   - Ensure plugin is activated

3. **Database Tables Not Created**
   - Deactivate and reactivate the plugin
   - Check database user permissions
   - Verify MySQL version compatibility

4. **Migration Issues**
   - Ensure you've run the migration script before reactivating
   - Check database logs for any errors
   - Verify all required fields are present in the new schema

### Debug Mode
Enable WordPress debug mode in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## Support

For technical support, check the WordPress error logs and browser console for detailed error messages.




