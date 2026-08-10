import axios from 'axios';

// Create axios instance with base configuration
const api = axios.create({
  baseURL: window.REACT_APP_BASE_URL || process.env.REACT_APP_API_URL || 'http://localhost/wp-json/assessor/v1',
  timeout: 300000, // 5 minutes to allow for large data syncs
  headers: {
    'Content-Type': 'application/json',
  },
});

// Request interceptor to add auth token and cache busting
api.interceptors.request.use(
  (config) => {
    // Check sessionStorage for token (session-only mode)
    const token = sessionStorage.getItem('assessor_token');
    
    console.log('🔍 API Request Interceptor:', {
      url: config.url,
      method: config.method,
      hasToken: !!token,
      tokenPreview: token ? token.substring(0, 20) + '...' : 'none',
      tokenSource: 'sessionStorage'
    });
    
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
      console.log('✅ Authorization header added:', `Bearer ${token.substring(0, 20)}...`);
    } else {
      console.log('❌ No token found in storage');
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
  propertyState: (id) => `/properties/${id}/state`,
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
  revisionEntries: '/settings/revision-entries',
  requestPurposes: '/settings/request-purposes',
  requestPurposesDelete: '/settings/request-purposes/delete',
  publicApiKeys: '/settings/public-api-keys',
  
  // Export
  export: '/export',
  
  // Audit
  audit: '/audit',
  
  // Users
  users: '/users',
  user: (id) => `/users/${id}`,
  userAvatar: (id) => `/users/${id}/avatar`,
  
  // Requests
  requests: '/requests',
  request: (id) => `/requests/${id}`,
  requestStatistics: '/requests/statistics',

  // Sync
  syncPushNow: '/sync/push-now',
  syncQueueStatus: '/sync/queue-status',
  syncConfig: '/sync/config',
  syncGenerateToken: '/sync/generate-token',
  syncSaveToken: '/sync/save-token',
  syncClearFailed: '/sync/clear-failed',
  syncMissingFilesList: '/sync/missing-files-list',
  syncDownloadBatch: '/sync/download-batch',
  syncDownloadStatus: '/sync/download-status',

  // Hardware Lock
  hardwareLockStatus: '/hardware-lock/status',
  hardwareLockActivate: '/hardware-lock/activate',

  // ETRACS Module
  etracsFaas: '/etracs/faas',
  etracsFaasItem: (id) => `/etracs/faas/${id}`,
  etracsFaasCancel: (id) => `/etracs/faas/${id}/cancel`,
  etracsFaasSignatory: (id) => `/etracs/faas/${id}/signatory`,
  etracsStats: '/etracs/stats',
  etracsTransactionTypes: '/etracs/transaction-types',
  etracsEntities: '/etracs/entities',
  etracsEntityItem: (id) => `/etracs/entities/${id}`,
  etracsPullSync: '/etracs/pull',
  etracsBarangay: '/etracs/barangay',
  etracsClassifications: '/etracs/classifications',
  etracsExemptionTypes: '/etracs/exemption-types',
  etracsBuildingLookups: '/etracs/building/lookups',
  etracsRpuAssessment: (rpuid) => `/etracs/rpu/${rpuid}/assessment`,
  etracsRpuDetail: (rpuid) => `/etracs/rpu/${rpuid}/detail`,
};

// API functions
export const apiService = {
  // Authentication
  login: async (credentials) => {
    try {
      // Avoid logging sensitive credentials
      
      const response = await api.post(endpoints.login, credentials);
      
      return response.data;
    } catch (error) {
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

  updatePropertyState: async (id, state) => {
    try {
      const response = await api.put(endpoints.propertyState(id), { state });
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
  getPropertyTypes: async () => {
    try {
      const response = await api.get(endpoints.propertyTypes);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getRequestPurposes: async () => {
    try {
      const response = await api.get(endpoints.requestPurposes);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  saveRequestPurpose: async (payload) => {
    try {
      const response = await api.post(endpoints.requestPurposes, payload);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  deleteRequestPurpose: async (id) => {
    try {
      const response = await api.post(endpoints.requestPurposesDelete, { id });
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
  getPublicApiKeys: async () => {
    try {
      const response = await api.get(endpoints.publicApiKeys);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  generatePublicApiKey: async (payload) => {
    try {
      const response = await api.post(endpoints.publicApiKeys, payload || {});
      let data = response.data;
      if (typeof data === 'string') {
        const parts = data
          .replace(/<[^>]*>/g, '')
          .split(/\\n|\r\n|\n|\r/)
          .map((p) => p.trim())
          .filter(Boolean);
        if (parts.length >= 2 && parts[0].startsWith('assessor_')) {
          data = {
            success: true,
            id: parts[2] != null ? parseInt(parts[2], 10) : undefined,
            api_key: parts[0],
            api_secret: parts[1],
          };
        } else {
          try {
            data = JSON.parse(data);
          } catch {
            data = {};
          }
        }
      }
      return { data, headers: response.headers || {} };
    } catch (error) {
      throw handleApiError(error);
    }
  },
  updatePublicApiKey: async (id, payload) => {
    try {
      const response = await api.put(`${endpoints.publicApiKeys}/${id}`, payload);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  revokePublicApiKey: async (id) => {
    try {
      const response = await api.delete(`${endpoints.publicApiKeys}/${id}`);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  revealPublicApiSecret: async (id, password) => {
    try {
      const response = await api.post(`${endpoints.publicApiKeys}/${id}/reveal-secret`, { password });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  setPublicApiEnabled: async (enabled) => {
    try {
      const response = await api.post('/settings/public-api-enabled', { enabled });
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

  // Revision Entries
  getRevisionEntries: async () => {
    try {
      const response = await api.get(endpoints.revisionEntries);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  saveRevisionEntry: async (revisionEntry) => {
    try {
      const response = await api.post(endpoints.revisionEntries, revisionEntry);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  deleteRevisionEntry: async (id) => {
    try {
      const response = await api.post(endpoints.revisionEntries + '/delete', { id });
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
      console.log('🔍 API Service: Current token:', sessionStorage.getItem('assessor_token'));
      
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
  uploadUserAvatar: async (id, file) => {
    try {
      const formData = new FormData();
      formData.append('avatar', file);
      const response = await api.post(endpoints.userAvatar(id), formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
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
  

  
  // Sync
  triggerSync: async (forceFull = false) => {
    try {
      const body = forceFull ? { force_full: true } : {};
      const response = await api.post(endpoints.syncPushNow, body);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  getSyncConfig: async () => {
    try {
      const response = await api.get(endpoints.syncConfig);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  generateSyncToken: async () => {
    try {
      const response = await api.post(endpoints.syncGenerateToken);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  saveSyncToken: async (token) => {
    try {
      const response = await api.post(endpoints.syncSaveToken, { token });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  getDownloadStatus: async () => {
    try {
      const response = await api.get(endpoints.syncDownloadStatus);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  triggerFileDownload: async () => {
    try {
      const response = await api.post(endpoints.syncDownloadFiles, {}, { timeout: 600000 });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  getMissingFilesList: async () => {
    try {
      const response = await api.get(endpoints.syncMissingFilesList);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  downloadBatch: async (files) => {
    try {
      const response = await api.post(endpoints.syncDownloadBatch, { files }, { timeout: 120000 });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // Hardware Lock
  getHardwareLockStatus: async () => {
    try {
      const response = await api.get(endpoints.hardwareLockStatus);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  activateHardwareLock: async (activationKey) => {
    try {
      const response = await api.post(endpoints.hardwareLockActivate, { activation_key: activationKey });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },

  // ETRACS Sync
  pullEtracsSync: async () => {
    try {
      const response = await api.post(endpoints.etracsPullSync, {}, { timeout: 300000 });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  }
};

// Error handling utility
export const handleApiError = (error) => {
  if (error.response) {
    // Server responded with error status
    const { status, data } = error.response;
    
    if (data && data.message) {
      const e = new Error(data.message);
      e.status = status;
      e.data = data;
      return e;
    }
    
    switch (status) {
      case 400:
        // Check for specific error types
        if (data && data.code === 'duplicate_tax_number') {
          const e = new Error('Tax Declaration Number already exists. Please use a different number.');
          e.status = status;
          e.data = data;
          return e;
        }
        if (data && data.code === 'duplicate_receipt') {
          const e = new Error('This receipt number already exists. Please use a different receipt number.');
          e.status = status;
          e.data = data;
          return e;
        }
        if (data && data.code === 'missing_field') {
          const e = new Error(`Required field missing: ${data.message}`);
          e.status = status;
          e.data = data;
          return e;
        }
        if (data && data.code === 'invalid_amount') {
          const e = new Error('Amount must be a positive number.');
          e.status = status;
          e.data = data;
          return e;
        }
        if (data && data.code === 'invalid_date') {
          const e = new Error('Please enter a valid date.');
          e.status = status;
          e.data = data;
          return e;
        }
        {
          const e = new Error('Bad request. Please check your input.');
          e.status = status;
          e.data = data;
          return e;
        }
      case 401:
        {
          const e = new Error('Unauthorized. Please log in again.');
          e.status = status;
          e.data = data;
          return e;
        }
      case 403:
        {
          if (data && data.code === 'hardware_locked') {
             // Handle hardware locked state globally
             window.dispatchEvent(new Event('hardware_locked'));
             const e = new Error('Hardware locked. Activation required.');
             e.status = status;
             e.data = data;
             return e;
          }
          const e = new Error('Access denied. You do not have permission for this action.');
          e.status = status;
          e.data = data;
          return e;
        }
      case 404:
        {
          const e = new Error('Resource not found.');
          e.status = status;
          e.data = data;
          return e;
        }
      case 422:
        {
          const e = new Error('Validation error. Please check your input.');
          e.status = status;
          e.data = data;
          return e;
        }
      case 500:
        {
          const e = new Error('Server error. Please try again later.');
          e.status = status;
          e.data = data;
          return e;
        }
      default:
        {
          const e = new Error(`Request failed with status ${status}`);
          e.status = status;
          e.data = data;
          return e;
        }
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

// ──────────────────────────────────────────
// ETRACS API Service Methods
// ──────────────────────────────────────────
export const etracsService = {
  // FAAS CRUD
  getFaasList: async (params = {}) => {
    try {
      const response = await api.get(endpoints.etracsFaas, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getFaas: async (id) => {
    try {
      const response = await api.get(endpoints.etracsFaasItem(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  createFaas: async (data) => {
    try {
      const response = await api.post(endpoints.etracsFaas, data);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  updateFaas: async (id, data) => {
    try {
      const response = await api.put(endpoints.etracsFaasItem(id), data);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  deleteFaas: async (id) => {
    try {
      const response = await api.delete(endpoints.etracsFaasItem(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  cancelFaas: async (id, data) => {
    try {
      const response = await api.post(endpoints.etracsFaasCancel(id), data);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  // Stats
  getStats: async () => {
    try {
      const response = await api.get(endpoints.etracsStats);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  // Transaction types
  getTransactionTypes: async () => {
    try {
      const response = await api.get(endpoints.etracsTransactionTypes);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  // Entities
  getEntities: async (params = {}) => {
    try {
      const response = await api.get(endpoints.etracsEntities, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  createEntity: async (data) => {
    try {
      const response = await api.post(endpoints.etracsEntities, data);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  updateEntity: async (id, data) => {
    try {
      const response = await api.put(endpoints.etracsEntityItem(id), data);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  deleteEntity: async (id) => {
    try {
      const response = await api.delete(endpoints.etracsEntityItem(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  pullSync: async () => {
    try {
      const response = await api.post(endpoints.etracsPullSync);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  
  // ── New lookups and detail endpoints ──
  
  getBarangays: async (params = {}) => {
    try {
      const response = await api.get(endpoints.etracsBarangay, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getClassifications: async () => {
    try {
      const response = await api.get(endpoints.etracsClassifications);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getExemptionTypes: async () => {
    try {
      const response = await api.get(endpoints.etracsExemptionTypes);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getRpuAssessment: async (rpuid) => {
    try {
      const response = await api.get(endpoints.etracsRpuAssessment(rpuid));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getRpuDetail: async (rpuid) => {
    try {
      const response = await api.get(endpoints.etracsRpuDetail(rpuid));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getFaasSignatory: async (id) => {
    try {
      const response = await api.get(endpoints.etracsFaasSignatory(id));
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  updateFaasSignatory: async (id, data) => {
    try {
      const response = await api.put(endpoints.etracsFaasSignatory(id), data);
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
  getBuildingLookups: async (params = {}) => {
    try {
      const response = await api.get(endpoints.etracsBuildingLookups, { params });
      return response.data;
    } catch (error) {
      throw handleApiError(error);
    }
  },
};

export default api;
