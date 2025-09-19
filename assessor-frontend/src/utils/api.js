import axios from 'axios';

// Create axios instance with base configuration
const api = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://localhost/wp-json/assessor/v1',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Request interceptor to add auth token and cache busting
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('assessor_token');
    console.log('🔍 API Request Interceptor:', {
      url: config.url,
      method: config.method,
      hasToken: !!token,
      tokenPreview: token ? token.substring(0, 20) + '...' : 'none'
    });
    
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
      console.log('✅ Authorization header added:', `Bearer ${token.substring(0, 20)}...`);
    } else {
      console.log('❌ No token found in localStorage');
    }
    
    // Add cache busting headers for Hostinger. Enable this for Hostinger.
    // config.headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
    // config.headers['Pragma'] = 'no-cache';
    // config.headers['Expires'] = '0';
    
    // Add cache busting parameter to URL
    const separator = config.url.includes('?') ? '&' : '?';
    config.url = `${config.url}${separator}_t=${Date.now()}&_v=${process.env.REACT_APP_VERSION || '1.0.0'}`;
    
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Do not clear auth automatically; let views decide how to handle
      console.log('🔍 API Service: 401 Unauthorized - letting caller handle');
    }
    return Promise.reject(error);
  }
);

// API endpoints
export const endpoints = {
  // Authentication
  login: '/login',
  logout: '/logout',
  validateToken: '/validate-token',
  
  // Properties
  properties: '/properties',
  property: (id) => `/properties/${id}`,
  propertyVersions: (id) => `/properties/${id}/versions`,
  propertyDocuments: (id) => `/properties/${id}/documents`,
  propertyDocument: (id, docId) => `/properties/${id}/documents/${docId}`,
  taxDeclarationHistory: (taxNumber) => `/tax-declaration-history/${taxNumber}`,
  propertyByTaxNumber: (taxNumber) => `/properties/by-tax-number/${taxNumber}`,
  
  // Dashboard
  dashboard: '/dashboard',

  // Settings
  settings: '/settings',
  settingsLogo: '/settings/logo',
  settingsHeaderPhoto: '/settings/header-photo',
  propertyTypes: '/settings/property-types',
  generalClasses: '/settings/general-classes',
  locations: '/settings/locations',
  
  // Export
  export: '/export',
  
  // Audit
  audit: '/audit',
  
  // Users
  users: '/users',
  user: (id) => `/users/${id}`,
  
  // Requests
  requests: '/requests',
  request: (id) => `/requests/${id}`,
  requestStatistics: '/requests/statistics',
};

// API functions
export const apiService = {
  // Authentication
  login: async (credentials) => {
    try {
      console.log('🔍 API Service: Making login request to:', endpoints.login);
      console.log('🔍 API Service: Credentials:', { username: credentials.username });
      
      const response = await api.post(endpoints.login, credentials);
      console.log('🔍 API Service: Login response received:', response.data);
      
      return response.data;
    } catch (error) {
      console.error('❌ API Service: Login request failed:', error);
      throw handleApiError(error);
    }
  },

  logout: async () => {
    try {
      const response = await api.post(endpoints.logout);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  validateToken: async () => {
    try {
      const response = await api.get(endpoints.validateToken);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Properties
  getProperties: async (params = {}) => {
    try {
      const response = await api.get(endpoints.properties, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  getProperty: async (id) => {
    try {
      const response = await api.get(endpoints.property(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  createProperty: async (propertyData) => {
    try {
      const response = await api.post(endpoints.properties, propertyData);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  updateProperty: async (id, propertyData) => {
    try {
      const response = await api.put(endpoints.property(id), propertyData);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  deleteProperty: async (id) => {
    try {
      const response = await api.delete(endpoints.property(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Tax Declaration History
  getTaxDeclarationHistory: async (taxNumber) => {
    try {
      const response = await api.get(endpoints.taxDeclarationHistory(taxNumber));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  getPropertyByTaxNumber: async (taxNumber) => {
    try {
      const response = await api.get(endpoints.propertyByTaxNumber(taxNumber));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Property Versions
  getPropertyVersions: async (propertyId) => {
    try {
      const response = await api.get(endpoints.propertyVersions(propertyId));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Property Documents
  getPropertyDocuments: async (propertyId) => {
    try {
      const response = await api.get(endpoints.propertyDocuments(propertyId));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  deletePropertyDocument: async (propertyId, docId) => {
    try {
      const response = await api.delete(endpoints.propertyDocument(propertyId, docId));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Settings
  getSettings: async () => {
    try {
      const response = await api.get(endpoints.settings);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getPropertyTypes: async () => {
    try {
      const response = await api.get(endpoints.propertyTypes);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getGeneralClasses: async () => {
    try {
      const response = await api.get(endpoints.generalClasses);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getLocations: async () => {
    try {
      const response = await api.get(endpoints.locations);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  saveSettings: async (settings) => {
    try {
      const response = await api.post(endpoints.settings, settings);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  uploadLogo: async (file) => {
    try {
      const formData = new FormData();
      formData.append('logo', file);
      const response = await api.post(endpoints.settingsLogo, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  uploadHeaderPhoto: async (file) => {
    try {
      const formData = new FormData();
      formData.append('header_photo', file);
      const response = await api.post(endpoints.settingsHeaderPhoto, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Property Types
  savePropertyType: async (propertyType) => {
    try {
      const response = await api.post(endpoints.propertyTypes, propertyType);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  deletePropertyType: async (id) => {
    try {
      const response = await api.post(endpoints.propertyTypes + '/delete', { id });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // General Classes
  saveGeneralClass: async (generalClass) => {
    try {
      const response = await api.post(endpoints.generalClasses, generalClass);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  deleteGeneralClass: async (id) => {
    try {
      const response = await api.post(endpoints.generalClasses + '/delete', { id });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  saveLocation: async (location) => {
    try {
      const response = await api.post(endpoints.locations, location);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  deleteLocation: async (id) => {
    try {
      const response = await api.post(endpoints.locations + '/delete', { id });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  uploadDocument: async (propertyId, formData, onUploadProgress) => {
    try {
      const response = await api.post(endpoints.propertyDocuments(propertyId), formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
        onUploadProgress,
      });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Dashboard
  getDashboardData: async (params = {}) => {
    try {
      console.log('🔍 API Service: Making dashboard request...', params);
      console.log('🔍 API Service: Current token:', localStorage.getItem('assessor_token'));
      
      const isProd = process.env.NODE_ENV === 'production';
      const response = await api.get(endpoints.dashboard, {
        headers: isProd ? {
          // Defeat intermediary/proxy caches in production (esp. Hostinger)
          'Cache-Control': 'no-cache, no-store, must-revalidate',
          'Pragma': 'no-cache',
          'Expires': '0'
        } : {},
        params
      });
      console.log('🔍 API Service: Dashboard response received:', response.data);
      return response.data;
    } catch (error) {
      console.error('❌ API Service: Dashboard request failed:', error);
      console.error('❌ API Service: Error response:', error.response?.data);
      console.error('❌ API Service: Error status:', error.response?.status);
      throw handleApiError(error);
    }
  },

  // Export
  exportData: async (exportConfig) => {
    try {
      const response = await api.post(endpoints.export, exportConfig, {
        responseType: 'blob',
      });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Audit Trail
  getAuditTrail: async (params = {}) => {
    try {
      const response = await api.get(endpoints.audit, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Users
  getUsers: async (params = {}) => {
    try {
      const response = await api.get(endpoints.users, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  createUser: async (user) => {
    try {
      const response = await api.post(endpoints.users, user);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  updateUser: async (id, user) => {
    try {
      const response = await api.put(endpoints.user(id), user);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  deleteUser: async (id) => {
    try {
      const response = await api.delete(endpoints.user(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Requests
  getRequests: async (params = {}) => {
    try {
      const response = await api.get(endpoints.requests, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  
  createRequest: async (requestData) => {
    try {
      const response = await api.post(endpoints.requests, requestData);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  
  getRequest: async (id) => {
    try {
      const response = await api.get(endpoints.request(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  
  updateRequest: async (id, requestData) => {
    try {
      const response = await api.put(endpoints.request(id), requestData);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  
  deleteRequest: async (id) => {
    try {
      const response = await api.delete(endpoints.request(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  
  getRequestStatistics: async (params = {}) => {
    try {
      const response = await api.get(endpoints.requestStatistics, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },


};

// Error handling utility
export const handleApiError = (error) => {
  if (error.response) {
    // Server responded with error status
    const { status, data } = error.response;
    
    if (data && data.message) {
      return new Error(data.message);
    }
    
    switch (status) {
      case 400:
        // Check for specific error types
        if (data && data.code === 'duplicate_receipt') {
          return new Error('This receipt number already exists. Please use a different receipt number.');
        }
        if (data && data.code === 'missing_field') {
          return new Error(`Required field missing: ${data.message}`);
        }
        if (data && data.code === 'invalid_amount') {
          return new Error('Amount must be a positive number.');
        }
        if (data && data.code === 'invalid_date') {
          return new Error('Please enter a valid date.');
        }
        return new Error('Bad request. Please check your input.');
      case 401:
        return new Error('Unauthorized. Please log in again.');
      case 403:
        return new Error('Access denied. You do not have permission for this action.');
      case 404:
        return new Error('Resource not found.');
      case 422:
        return new Error('Validation error. Please check your input.');
      case 500:
        return new Error('Server error. Please try again later.');
      default:
        return new Error(`Request failed with status ${status}`);
    }
  } else if (error.request) {
    // Request was made but no response received
    return new Error('No response from server. Please check your connection.');
  } else {
    // Something else happened
    return new Error('An unexpected error occurred.');
  }
};

// File upload utility
export const uploadFile = async (file, propertyId, description = '') => {
  const formData = new FormData();
  formData.append('document', file);
  formData.append('property_id', propertyId);
  formData.append('description', description);
  
  return await apiService.uploadDocument(propertyId, formData);
};

// Download utility
export const downloadFile = (url, filename) => {
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};

export default api;
