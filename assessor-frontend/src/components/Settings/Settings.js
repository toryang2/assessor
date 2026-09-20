import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Box, Card, CardContent, TextField, Button, Grid, Typography, Alert, Divider, List, ListItem, ListItemText, IconButton, Switch, FormControlLabel, Paper, Snackbar, ListItemIcon, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TableSortLabel, Dialog, DialogTitle, DialogContent, DialogActions, Menu, MenuItem, CircularProgress, Chip } from '@mui/material';
import DragIndicatorIcon from '@mui/icons-material/DragIndicator';
import DeleteIcon from '@mui/icons-material/Delete';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import EditIcon from '@mui/icons-material/Edit';
import CheckIcon from '@mui/icons-material/Check';
import CloseIcon from '@mui/icons-material/Close';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import TuneIcon from '@mui/icons-material/Tune';
import StorageIcon from '@mui/icons-material/Storage';
import EventRepeatIcon from '@mui/icons-material/EventRepeat';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import VpnKeyIcon from '@mui/icons-material/VpnKey';
import CloudSyncIcon from '@mui/icons-material/CloudSync';
import SyncAltIcon from '@mui/icons-material/SyncAlt';
import DomainIcon from '@mui/icons-material/Domain';
import { motion } from 'framer-motion';
import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';
import useLoadingWatchdog from '../../hooks/useLoadingWatchdog';
import EtracsBuildingRevisionSettings from './EtracsBuildingRevisionSettings';
import { formatAppDate } from '../../utils/dateTime';

const DEFAULT_API_SECRET_LENGTH = 48;

const maskApiSecret = (length) => '•'.repeat(length || DEFAULT_API_SECRET_LENGTH);

/** Decode one_time_credential from create-key API (avoids WAF stripping "secret" fields). */
const decodeOneTimeCredential = (encoded) => {
  if (!encoded || typeof encoded !== 'string') return '';
  try {
    const normalized = encoded.replace(/-/g, '+').replace(/_/g, '/');
    return atob(normalized);
  } catch {
    return '';
  }
};

const readHeaderCredential = (headers, name) => {
  if (!headers || typeof headers !== 'object') return '';
  const value = headers[name] ?? headers[name.toLowerCase()];
  return decodeOneTimeCredential(value);
};

/** Split create-key payload on real or literal newlines (production hosts vary). */
const splitCredentialLines = (raw) => {
  if (raw == null) return [];
  return String(raw)
    .replace(/<[^>]*>/g, '')
    .split(/\\n|\r\n|\n|\r/)
    .map((p) => p.trim())
    .filter(Boolean);
};

/** Parse plain-text create-key body (key + secret + id). */
const parsePlainCredentialText = (raw) => {
  const parts = splitCredentialLines(raw);
  if (parts.length >= 2 && parts[0].startsWith('assessor_')) {
    return {
      id: parts[2] != null ? parseInt(parts[2], 10) : undefined,
      api_key: parts[0],
      api_secret: parts[1],
    };
  }
  return null;
};

/** Ensure key and secret are separate (handles combined blob in one field). */
const normalizeCredentialPair = (apiKey, apiSecret) => {
  const keyStr = apiKey != null ? String(apiKey) : '';
  const secretStr = apiSecret != null ? String(apiSecret) : '';

  if (secretStr && keyStr.startsWith('assessor_') && !keyStr.includes('\n') && !keyStr.includes('\\n')) {
    return { apiKey: keyStr, apiSecret: secretStr };
  }

  const parsed = parsePlainCredentialText(keyStr) || parsePlainCredentialText(`${keyStr}\n${secretStr}`);
  if (parsed) {
    return { apiKey: parsed.api_key, apiSecret: secretStr || parsed.api_secret };
  }

  const parts = splitCredentialLines(keyStr);
  if (parts.length >= 2 && parts[0].startsWith('assessor_')) {
    return {
      apiKey: parts[0],
      apiSecret: secretStr || parts[1],
    };
  }

  return { apiKey: keyStr, apiSecret: secretStr };
};

/** Normalize POST /settings/public-api-keys create response (shape varies by deploy). */
const extractCreatedApiCredentials = (res, items, fallbackName) => {
  if (typeof res === 'string') {
    const plain = parsePlainCredentialText(res);
    if (plain) {
      return {
        id: plain.id ?? null,
        ...normalizeCredentialPair(plain.api_key, plain.api_secret),
        name: fallbackName,
      };
    }
  }

  const headers = (res && typeof res === 'object' && res.headers) ? res.headers : {};
  const headerKey = readHeaderCredential(headers, 'x-assessor-one-time-key');
  const headerSecret = readHeaderCredential(headers, 'x-assessor-one-time-credential');
  const headerId = headers['x-assessor-key-id'] ?? headers['X-Assessor-Key-Id'];

  const raw = res && typeof res === 'object' ? res : {};
  let payload;
  if (typeof raw.data === 'string') {
    payload = parsePlainCredentialText(raw.data) || {};
  } else if (raw.data && typeof raw.data === 'object') {
    payload = raw.data;
  } else {
    payload = {};
  }
  const nested = payload.item && typeof payload.item === 'object' ? payload.item : payload;
  const creds = payload.credentials && typeof payload.credentials === 'object' ? payload.credentials : null;
  const sorted = [...(items || [])].sort((a, b) => Number(b.id) - Number(a.id));
  const id = payload.id ?? nested.id ?? creds?.id ?? (headerId != null ? parseInt(headerId, 10) : null) ?? null;
  const fromList = id != null
    ? sorted.find((k) => String(k.id) === String(id))
    : sorted[0];

  let apiKey =
    headerKey ||
    payload.api_key ||
    payload.apiKey ||
    payload.plain_key ||
    nested.api_key ||
    nested.apiKey ||
    creds?.key ||
    fromList?.api_key ||
    '';
  let apiSecret =
    headerSecret ||
    payload.api_secret ||
    payload.apiSecret ||
    payload.plain_secret ||
    nested.api_secret ||
    nested.apiSecret ||
    payload.secret ||
    nested.secret ||
    creds?.token ||
    decodeOneTimeCredential(payload.one_time_credential) ||
    '';

  const normalized = normalizeCredentialPair(apiKey, apiSecret);
  const name = payload.name || nested.name || fromList?.name || fallbackName;

  return { id, apiKey: normalized.apiKey, apiSecret: normalized.apiSecret, name };
};

const apiKeyNameCellHoverSx = {
  '& .api-key-name-hover-action': {
    opacity: 0,
    transition: 'opacity 0.15s ease',
  },
  '&:hover .api-key-name-hover-action': {
    opacity: 1,
  },
};

const apiKeyValueCellHoverSx = {
  '& .api-key-value-hover-action': {
    opacity: 0,
    transition: 'opacity 0.15s ease',
  },
  '&:hover .api-key-value-hover-action': {
    opacity: 1,
  },
};

const apiKeySecretCellHoverSx = {
  '& .api-key-secret-hover-action': {
    opacity: 0,
    transition: 'opacity 0.15s ease',
  },
  '&:hover .api-key-secret-hover-action': {
    opacity: 1,
  },
};

const apiKeySecretTextSx = (charCount) => ({
  fontFamily: 'monospace',
  fontSize: '0.8rem',
  width: `${charCount}ch`,
  maxWidth: '100%',
  minWidth: `${charCount}ch`,
  overflow: 'hidden',
  textOverflow: 'ellipsis',
  whiteSpace: 'nowrap',
  display: 'inline-block',
  verticalAlign: 'middle',
  lineHeight: 1.5,
});

const DEFAULTS = {
  app_logo_url: '',
  header_photo_url: '',
  header_province: 'BUKIDNON',
  header_municipality: 'KITAOTAO',
  municipality_prefix: 'KIT',
  lgu_pin: '059-10',
  header_office: 'OFFICE OF THE MUNICIPAL ASSESSOR',
  request_place_issued_default: '',
  verifier_signatory_name: '',
  verifier_signatory_title: '',
  municipal_assessor_license: '',
  municipal_assessor_title: '',
  municipal_assessor_suffix: '',
  afk_timeout: 30,
  assessor_etracs_db_host: 'localhost',
  assessor_etracs_db_port: '3306',
  assessor_etracs_db_user: 'root',
  assessor_etracs_db_password: '',
  assessor_etracs_db_name: 'etracs254_kitaotao',
  enable_etracs_features: 0
};

const Settings = () => {
  const { canManage, isSuperAdmin, afkTimeout, updateAfkTimeout } = useAuth();
  const [form, setForm] = useState({
    ...DEFAULTS,
    afk_timeout: afkTimeout || DEFAULTS.afk_timeout
  });
  const [originalForm, setOriginalForm] = useState({
    ...DEFAULTS,
    afk_timeout: afkTimeout || DEFAULTS.afk_timeout
  });
  const [saved, setSaved] = useState(false);
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [propertyTypes, setPropertyTypes] = useState([]);
  const [generalClasses, setGeneralClasses] = useState([]);
  const [newType, setNewType] = useState({ code: '', name: '' });
  const [newClass, setNewClass] = useState({ code: '', name: '' });
  const [locations, setLocations] = useState([]);
  const [newLocation, setNewLocation] = useState({ code: '', name: '', pin: '' });
  const [revisionEntries, setRevisionEntries] = useState([]);
  const [newRevisionEntry, setNewRevisionEntry] = useState({ revision_year: '', from_year: '', to_year: '' });
  const [requestPurposes, setRequestPurposes] = useState([]);
  const [newRequestPurpose, setNewRequestPurpose] = useState({ purpose: '', amount: '0.00' });
  const [editingRequestPurposeId, setEditingRequestPurposeId] = useState(null);
  const [editRequestPurposeDraft, setEditRequestPurposeDraft] = useState({ purpose: '', amount: '0.00' });
  const [deletePurposeDialog, setDeletePurposeDialog] = useState({ open: false, row: null });
  const [pendingLogoFile, setPendingLogoFile] = useState(null);
  const [pendingLogoPreview, setPendingLogoPreview] = useState('');
  const [pendingHeaderPhotoFile, setPendingHeaderPhotoFile] = useState(null);
  const [pendingHeaderPhotoPreview, setPendingHeaderPhotoPreview] = useState('');
  const [dragging, setDragging] = useState({ key: null, from: -1 });
  const [activeTab, setActiveTab] = useState(0);
  const [initialLoad, setInitialLoad] = useState(true);
  const [publicApiKeys, setPublicApiKeys] = useState([]);
  const [newPublicApiKeyName, setNewPublicApiKeyName] = useState('');
  const [publicApiBusy, setPublicApiBusy] = useState(false);
  const [newKeyDialog, setNewKeyDialog] = useState({
    open: false,
    apiKey: '',
    apiSecret: '',
    name: '',
    dialogKey: 0,
    secretMissing: false,
  });
  const [revealedApiSecrets, setRevealedApiSecrets] = useState({});
  const [revealSecretDialog, setRevealSecretDialog] = useState({
    open: false,
    keyId: null,
    keyName: '',
    password: '',
    loading: false,
    error: '',
  });
  const [editingApiKeyId, setEditingApiKeyId] = useState(null);
  const [editApiKeyNameDraft, setEditApiKeyNameDraft] = useState('');
  const [deleteApiKeyDialog, setDeleteApiKeyDialog] = useState({ open: false, row: null });
  const [apiKeyMenuAnchor, setApiKeyMenuAnchor] = useState({ el: null, row: null });
  const [apiKeyDateSort, setApiKeyDateSort] = useState('desc');
  const revealPasswordInputRef = useRef(null);

  // Sync settings state
  const [syncConfig, setSyncConfig] = useState(null);
  const [syncConfigLoading, setSyncConfigLoading] = useState(false);
  const [generatedToken, setGeneratedToken] = useState('');
  const [tokenGenerating, setTokenGenerating] = useState(false);
  const [tokenSaving, setTokenSaving] = useState(false);
  const [syncSettingsMsg, setSyncSettingsMsg] = useState({ type: '', text: '' });
  const [pastedToken, setPastedToken] = useState('');
  const [etracsPulling, setEtracsPulling] = useState(false);
  const [syncProgress, setSyncProgress] = useState('Not running');

  useEffect(() => {
    let interval;
    if (etracsPulling) {
      interval = setInterval(async () => {
        try {
          const res = await apiService.getEtracsSyncStatus();
          if (res.status) {
            setSyncProgress(res.status);
          }
        } catch (e) {
          console.error('Failed to get sync status', e);
        }
      }, 1500);
    } else if (syncProgress !== 'Complete') {
      setSyncProgress('Not running');
    }
    return () => clearInterval(interval);
  }, [etracsPulling, syncProgress]);

  // Load sync config when user opens the Sync tab
  const [syncConfigError, setSyncConfigError] = useState(null);
  useEffect(() => {
    if (activeTab === 5 && canManage && !syncConfig && !syncConfigLoading) {
      (async () => {
        setSyncConfigLoading(true);
        setSyncConfigError(null);
        try {
          const data = await apiService.getSyncConfig();
          setSyncConfig(data);
        } catch (e) {
          console.error('Sync config error:', e);
          setSyncConfigError(e.message || 'Unknown error');
        }
        setSyncConfigLoading(false);
      })();
    }
  }, [activeTab, canManage]);

  const newKeyDialogCreds = useMemo(
    () => normalizeCredentialPair(newKeyDialog.apiKey, newKeyDialog.apiSecret),
    [newKeyDialog.apiKey, newKeyDialog.apiSecret]
  );

  // Keyboard shortcuts while editing a request purpose row
  useEffect(() => {
    if (!editingRequestPurposeId) return;

    const onKeyDown = (e) => {
      // Don't interfere with delete confirmation dialog
      if (deletePurposeDialog?.open) return;

      if (e.key === 'Escape') {
        e.preventDefault();
        cancelEditRequestPurpose();
        return;
      }

      if (e.key === 'Enter') {
        // Allow multiline/modified enters to behave normally
        if (e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) return;
        e.preventDefault();
        const row = (requestPurposes || []).find(r => r.id === editingRequestPurposeId);
        if (row) saveEditRequestPurpose(row);
      }
    };

    window.addEventListener('keydown', onKeyDown, { capture: true });
    return () => window.removeEventListener('keydown', onKeyDown, { capture: true });
  }, [editingRequestPurposeId, requestPurposes, deletePurposeDialog?.open, editRequestPurposeDraft]);

  // Keyboard shortcuts for delete confirmation dialog
  useEffect(() => {
    if (!deletePurposeDialog?.open) return;

    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeDeleteRequestPurpose();
        return;
      }
      if (e.key === 'Enter') {
        if (e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) return;
        e.preventDefault();
        doDeleteRequestPurpose();
      }
    };

    window.addEventListener('keydown', onKeyDown, { capture: true });
    return () => window.removeEventListener('keydown', onKeyDown, { capture: true });
  }, [deletePurposeDialog?.open, deletePurposeDialog?.row]);

  useEffect(() => {
    const load = async () => {
      try {
        const data = await apiService.getSettings();
        const loadedSettings = {
          app_logo_url: data.app_logo_url || DEFAULTS.app_logo_url,
          header_photo_url: data.header_photo_url || DEFAULTS.header_photo_url,
          header_province: data.header_province || DEFAULTS.header_province,
          header_municipality: data.header_municipality || DEFAULTS.header_municipality,
          municipality_prefix: data.municipality_prefix || DEFAULTS.municipality_prefix,
          lgu_pin: data.lgu_pin || DEFAULTS.lgu_pin,
          header_office: data.header_office || DEFAULTS.header_office,
          request_place_issued_default: data.request_place_issued_default || DEFAULTS.request_place_issued_default,
          verifier_signatory_name: data.verifier_signatory_name || DEFAULTS.verifier_signatory_name,
          verifier_signatory_title: data.verifier_signatory_title || DEFAULTS.verifier_signatory_title,
          municipal_assessor_name: data.municipal_assessor_name || DEFAULTS.municipal_assessor_name,
          municipal_assessor_license: data.municipal_assessor_license || DEFAULTS.municipal_assessor_license,
          municipal_assessor_title: data.municipal_assessor_title || DEFAULTS.municipal_assessor_title,
          municipal_assessor_suffix: data.municipal_assessor_suffix || DEFAULTS.municipal_assessor_suffix,
          afk_timeout: data.afk_timeout ?? afkTimeout ?? DEFAULTS.afk_timeout,
          assessor_etracs_db_host: data.assessor_etracs_db_host || DEFAULTS.assessor_etracs_db_host,
          assessor_etracs_db_port: data.assessor_etracs_db_port || DEFAULTS.assessor_etracs_db_port,
          assessor_etracs_db_user: data.assessor_etracs_db_user || DEFAULTS.assessor_etracs_db_user,
          assessor_etracs_db_password: data.assessor_etracs_db_password || '',
          assessor_etracs_db_name: data.assessor_etracs_db_name || DEFAULTS.assessor_etracs_db_name,
          enable_etracs_features: data.enable_etracs_features ?? DEFAULTS.enable_etracs_features,
        };
        setForm(loadedSettings);
        setOriginalForm(loadedSettings);
        const [typesRes, classesRes, locationsRes, revisionEntriesRes] = await Promise.all([
          apiService.getPropertyTypes(),
          apiService.getGeneralClasses(),
          apiService.getLocations(),
          apiService.getRevisionEntries()
        ]);
        setPropertyTypes(typesRes?.items || []);
        setGeneralClasses(classesRes?.items || []);
        setLocations(locationsRes?.items || []);
        setRevisionEntries(revisionEntriesRes?.items || []);

        const requestPurposesRes = await apiService.getRequestPurposes();
        setRequestPurposes(requestPurposesRes?.items || []);

        const keysRes = await apiService.getPublicApiKeys();
        setPublicApiKeys(keysRes?.items || []);
      } catch (e) {
        // fallback to defaults silently
      }
      setInitialLoad(false);
    };
    load();
  }, []);

  // Safety watchdog for initial settings load
  useLoadingWatchdog({
    isLoading: initialLoad,
    isInitialLoad: initialLoad,
    setLoading: setInitialLoad,
    setError: (msg) => setToast({ open: true, message: msg, severity: 'error' }),
    componentName: 'Settings',
    timeoutMs: 20000,
    timeoutMessage: 'Settings failed to load in time. Please refresh.',
    enabled: true
  });

  // Add refresh function for cache busting
  const refreshData = async () => {
    try {
      const data = await apiService.getSettings();
      const loadedSettings = {
        app_logo_url: data.app_logo_url || DEFAULTS.app_logo_url,
        header_photo_url: data.header_photo_url || DEFAULTS.header_photo_url,
        header_province: data.header_province || DEFAULTS.header_province,
        header_municipality: data.header_municipality || DEFAULTS.header_municipality,
        municipality_prefix: data.municipality_prefix || DEFAULTS.municipality_prefix,
        lgu_pin: data.lgu_pin || DEFAULTS.lgu_pin,
        header_office: data.header_office || DEFAULTS.header_office,
        request_place_issued_default: data.request_place_issued_default || DEFAULTS.request_place_issued_default,
        verifier_signatory_name: data.verifier_signatory_name || DEFAULTS.verifier_signatory_name,
        verifier_signatory_title: data.verifier_signatory_title || DEFAULTS.verifier_signatory_title,
        municipal_assessor_name: data.municipal_assessor_name || DEFAULTS.municipal_assessor_name,
        municipal_assessor_license: data.municipal_assessor_license || DEFAULTS.municipal_assessor_license,
        municipal_assessor_title: data.municipal_assessor_title || DEFAULTS.municipal_assessor_title,
        municipal_assessor_suffix: data.municipal_assessor_suffix || DEFAULTS.municipal_assessor_suffix,
        afk_timeout: data.afk_timeout ?? afkTimeout ?? DEFAULTS.afk_timeout,
        assessor_etracs_db_host: data.assessor_etracs_db_host || DEFAULTS.assessor_etracs_db_host,
        assessor_etracs_db_port: data.assessor_etracs_db_port || DEFAULTS.assessor_etracs_db_port,
        assessor_etracs_db_user: data.assessor_etracs_db_user || DEFAULTS.assessor_etracs_db_user,
        assessor_etracs_db_password: data.assessor_etracs_db_password || '',
        assessor_etracs_db_name: data.assessor_etracs_db_name || DEFAULTS.assessor_etracs_db_name,
        enable_etracs_features: data.enable_etracs_features ?? DEFAULTS.enable_etracs_features,
      };
      setForm(loadedSettings);
      setOriginalForm(loadedSettings);
      const [typesRes, classesRes, locationsRes, revisionEntriesRes] = await Promise.all([
        apiService.getPropertyTypes(),
        apiService.getGeneralClasses(),
        apiService.getLocations(),
        apiService.getRevisionEntries()
      ]);
      setPropertyTypes(typesRes?.items || []);
      setGeneralClasses(classesRes?.items || []);
      setLocations(locationsRes?.items || []);
      setRevisionEntries(revisionEntriesRes?.items || []);

      const requestPurposesRes = await apiService.getRequestPurposes();
      setRequestPurposes(requestPurposesRes?.items || []);

      const keysRes = await apiService.getPublicApiKeys();
      setPublicApiKeys(keysRes?.items || []);
    } catch (e) {
      // fallback to defaults silently
    }
  };

  const formatApiKeyDate = (value) => formatAppDate(value);

  const sortedPublicApiKeys = useMemo(() => {
    const items = [...(publicApiKeys || [])];
    items.sort((a, b) => {
      const ta = a.created_at ? new Date(a.created_at).getTime() : 0;
      const tb = b.created_at ? new Date(b.created_at).getTime() : 0;
      if (ta === tb) return 0;
      return apiKeyDateSort === 'asc' ? ta - tb : tb - ta;
    });
    return items;
  }, [publicApiKeys, apiKeyDateSort]);

  const isFormDirty = useMemo(() => {
    if (pendingLogoFile || pendingHeaderPhotoFile) return true;
    return Object.keys(originalForm).some(key => form[key] !== originalForm[key]);
  }, [form, originalForm, pendingLogoFile, pendingHeaderPhotoFile]);

  if (!canManage) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography variant="h6" color="error">
          Access Denied: Only Administrators and Municipal Assessors can access this page
        </Typography>
      </Box>
    );
  }

  const handleChange = (field, value) => {
    setForm(prev => ({ ...prev, [field]: value }));
  };

  const handleAfkTimeoutChange = (value) => {
    const timeoutValue = parseInt(value, 10);
    if (!isNaN(timeoutValue) && timeoutValue >= 5 && timeoutValue <= 480) { // 5 minutes to 8 hours
      setForm(prev => ({ ...prev, afk_timeout: timeoutValue }));
    } else if (value === '') {
      // Allow empty input temporarily while user is typing
      setForm(prev => ({ ...prev, afk_timeout: '' }));
    }
  };

  const handleSave = async () => {
    try {
      // Upload pending logo first (if any), but only on Save
      if (pendingLogoFile) {
        try {
          const res = await apiService.uploadLogo(pendingLogoFile);
          setForm(prev => ({ ...prev, app_logo_url: res.app_logo_url }));
          // clear pending preview
          if (pendingLogoPreview) {
            try { URL.revokeObjectURL(pendingLogoPreview); } catch (e) { }
          }
          setPendingLogoFile(null);
          setPendingLogoPreview('');
        } catch (uploadErr) {
          setToast({ open: true, message: 'Failed to upload logo.', severity: 'error' });
          return;
        }
      }

      // Upload pending header photo first (if any), but only on Save
      if (pendingHeaderPhotoFile) {
        try {
          const res = await apiService.uploadHeaderPhoto(pendingHeaderPhotoFile);
          setForm(prev => ({ ...prev, header_photo_url: res.header_photo_url }));
          // clear pending preview
          if (pendingHeaderPhotoPreview) {
            try { URL.revokeObjectURL(pendingHeaderPhotoPreview); } catch (e) { }
          }
          setPendingHeaderPhotoFile(null);
          setPendingHeaderPhotoPreview('');
        } catch (uploadErr) {
          setToast({ open: true, message: 'Failed to upload header photo.', severity: 'error' });
          return;
        }
      }

      const payload = {
        header_province: form.header_province,
        header_municipality: form.header_municipality,
        municipality_prefix: form.municipality_prefix,
        lgu_pin: form.lgu_pin,
        header_office: form.header_office,
        request_place_issued_default: form.request_place_issued_default,
        verifier_signatory_name: form.verifier_signatory_name,
        verifier_signatory_title: form.verifier_signatory_title,
        municipal_assessor_name: form.municipal_assessor_name,
        municipal_assessor_license: form.municipal_assessor_license,
        municipal_assessor_title: form.municipal_assessor_title,
        municipal_assessor_suffix: form.municipal_assessor_suffix,
        afk_timeout: form.afk_timeout ?? 30,
        assessor_etracs_db_host: form.assessor_etracs_db_host,
        assessor_etracs_db_port: form.assessor_etracs_db_port,
        assessor_etracs_db_user: form.assessor_etracs_db_user,
        assessor_etracs_db_password: form.assessor_etracs_db_password,
        assessor_etracs_db_name: form.assessor_etracs_db_name,
        enable_etracs_features: form.enable_etracs_features,
      };
      const saved = await apiService.saveSettings(payload);
      setForm(saved);
      setOriginalForm(saved);

      // Update the auth context with the new AFK timeout
      updateAfkTimeout(form.afk_timeout);

      // Dispatch event for Layout.js to update sidebar menus instantly
      window.dispatchEvent(new CustomEvent('settingsUpdated', { detail: saved }));

      setToast({ open: true, message: 'Saved successfully.', severity: 'success' });
    } catch (e) {
      setToast({ open: true, message: 'Failed to save settings.', severity: 'error' });
    }
  };

  const handleLogoUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    // Do not upload immediately; just stage and preview
    if (pendingLogoPreview) {
      try { URL.revokeObjectURL(pendingLogoPreview); } catch (e) { }
    }
    const previewUrl = URL.createObjectURL(file);
    setPendingLogoFile(file);
    setPendingLogoPreview(previewUrl);
  };

  const handleHeaderPhotoUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    // Do not upload immediately; just stage and preview
    if (pendingHeaderPhotoPreview) {
      try { URL.revokeObjectURL(pendingHeaderPhotoPreview); } catch (e) { }
    }
    const previewUrl = URL.createObjectURL(file);
    setPendingHeaderPhotoFile(file);
    setPendingHeaderPhotoPreview(previewUrl);
  };

  // Drag & Drop sorting helpers
  const handleDragStart = (key, fromIndex) => {
    setDragging({ key, from: fromIndex });
  };

  const handleDrop = async (key, toIndex) => {
    if (dragging.key !== key || dragging.from === -1 || dragging.from === toIndex) {
      setDragging({ key: null, from: -1 });
      return;
    }
    const reorder = (arr) => {
      const next = arr.slice();
      const [moved] = next.splice(dragging.from, 1);
      next.splice(toIndex, 0, moved);
      return next;
    };
    try {
      if (key === 'propertyTypes') {
        const next = reorder(propertyTypes);
        setPropertyTypes(next);
        await Promise.all(next.map((item, idx) => apiService.savePropertyType({ id: item.id, code: item.code, name: item.name, status: item.status, sort_order: idx + 1 })));
      } else if (key === 'generalClasses') {
        const next = reorder(generalClasses);
        setGeneralClasses(next);
        await Promise.all(next.map((item, idx) => apiService.saveGeneralClass({ id: item.id, code: item.code, name: item.name, status: item.status, sort_order: idx + 1 })));
      } else if (key === 'locations') {
        const next = reorder(locations);
        setLocations(next);
        await Promise.all(next.map((item, idx) => apiService.saveLocation({ id: item.id, code: item.code, name: item.name, status: item.status, sort_order: idx + 1 })));
      } else if (key === 'revisionEntries') {
        const next = reorder(revisionEntries);
        setRevisionEntries(next);
        await Promise.all(next.map((item, idx) => apiService.saveRevisionEntry({ id: item.id, revision_year: item.revision_year, from_year: item.from_year, to_year: item.to_year, status: item.status, sort_order: idx + 1 })));
      } else if (key === 'requestPurposes') {
        const next = reorder(requestPurposes);
        setRequestPurposes(next);
        await Promise.all(next.map((item, idx) => apiService.saveRequestPurpose({ id: item.id, purpose: item.purpose, amount: item.amount, status: item.status, sort_order: idx + 1 })));
      }
    } finally {
      setDragging({ key: null, from: -1 });
    }
  };

  // Add helpers to reuse for Enter key and button clicks
  const addPropertyType = async () => {
    if (!newType.code || !newType.name) {
      setToast({ open: true, message: 'Property Type: Code and Name are required.', severity: 'error' });
      return;
    }
    try {
      const res = await apiService.savePropertyType({ code: newType.code, name: newType.name, status: 'active' });
      setPropertyTypes(res?.items || []);
      setNewType({ code: '', name: '' });
      setToast({ open: true, message: 'Property type saved.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: 'Failed to save property type.', severity: 'error' });
    }
  };

  const addGeneralClass = async () => {
    if (!newClass.code || !newClass.name) {
      setToast({ open: true, message: 'General Class: Code and Name are required.', severity: 'error' });
      return;
    }
    try {
      const res = await apiService.saveGeneralClass({ code: newClass.code, name: newClass.name, status: 'active' });
      setGeneralClasses(res?.items || []);
      setNewClass({ code: '', name: '' });
      setToast({ open: true, message: 'General class saved.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: 'Failed to save general class.', severity: 'error' });
    }
  };

  const addLocation = async () => {
    if (!newLocation.code || !newLocation.name) {
      setToast({ open: true, message: 'Location: Code and Name are required.', severity: 'error' });
      return;
    }
    try {
      const res = await apiService.saveLocation({ code: newLocation.code, name: newLocation.name, pin: newLocation.pin, status: 'active' });
      setLocations(res?.items || []);
      setNewLocation({ code: '', name: '', pin: '' });
      setToast({ open: true, message: 'Barangay saved.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: 'Failed to save barangay.', severity: 'error' });
    }
  };

  const addRevisionEntry = async () => {
    if (!newRevisionEntry.revision_year || !newRevisionEntry.from_year) {
      setToast({ open: true, message: 'Revision Entry: Revision Year and From Year are required.', severity: 'error' });
      return;
    }

    // Auto-set to_year to 'present' if blank
    let toYear = newRevisionEntry.to_year.trim();
    if (!toYear) {
      toYear = 'present';
    }

    // Validate to_year - must be a number or 'present'
    const toYearLower = toYear.toLowerCase();
    if (toYearLower !== 'present' && (isNaN(toYearLower) || parseInt(toYearLower) < 1900 || parseInt(toYearLower) > 2100)) {
      setToast({ open: true, message: 'To Year: Must be a valid year (1900-2100) or leave blank for "present".', severity: 'error' });
      return;
    }
    try {
      const res = await apiService.saveRevisionEntry({
        revision_year: newRevisionEntry.revision_year,
        from_year: newRevisionEntry.from_year,
        to_year: toYear,
        status: 'active'
      });
      setRevisionEntries(res?.items || []);
      setNewRevisionEntry({ revision_year: '', from_year: '', to_year: '' });

      // Check if any previous revisions were updated
      const updatedRevisions = res?.updated_previous_revisions || 0;
      if (updatedRevisions > 0) {
        setToast({
          open: true,
          message: `Revision entry saved. ${updatedRevisions} previous revision(s) were automatically updated to end before this new revision.`,
          severity: 'info'
        });
      } else {
        setToast({ open: true, message: 'Revision entry saved.', severity: 'success' });
      }
    } catch (err) {
      setToast({ open: true, message: 'Failed to save revision entry.', severity: 'error' });
    }
  };

  const addRequestPurpose = async () => {
    if (!newRequestPurpose.purpose?.trim()) {
      setToast({ open: true, message: 'Purpose is required.', severity: 'error' });
      return;
    }
    const n = Number(newRequestPurpose.amount);
    if (newRequestPurpose.amount === '' || isNaN(n) || n < 0) {
      setToast({ open: true, message: 'Amount must be a valid number (0 or higher).', severity: 'error' });
      return;
    }
    try {
      const res = await apiService.saveRequestPurpose({
        purpose: newRequestPurpose.purpose.trim().replace(/\s+/g, '_'),
        amount: n,
        status: 'active',
        sort_order: (requestPurposes?.length || 0) + 1
      });
      setRequestPurposes(res?.items || []);
      setNewRequestPurpose({ purpose: '', amount: '0.00' });
      setToast({ open: true, message: 'Request purpose saved.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: err?.message || 'Failed to save request purpose.', severity: 'error' });
    }
  };

  const beginEditRequestPurpose = (row) => {
    setEditingRequestPurposeId(row.id);
    setEditRequestPurposeDraft({
      purpose: row.purpose ?? '',
      amount: (() => {
        const n = Number(row.amount);
        return isNaN(n) ? '0.00' : n.toFixed(2);
      })()
    });
  };

  const cancelEditRequestPurpose = () => {
    setEditingRequestPurposeId(null);
    setEditRequestPurposeDraft({ purpose: '', amount: '0.00' });
  };

  const saveEditRequestPurpose = async (row) => {
    const purpose = String(editRequestPurposeDraft.purpose || '').trim().replace(/\s+/g, '_');
    const amountNum = Number(editRequestPurposeDraft.amount);

    if (!purpose) {
      setToast({ open: true, message: 'Purpose is required.', severity: 'error' });
      return;
    }
    if (editRequestPurposeDraft.amount === '' || isNaN(amountNum) || amountNum < 0) {
      setToast({ open: true, message: 'Amount must be a valid number (0 or higher).', severity: 'error' });
      return;
    }

    try {
      const res = await apiService.saveRequestPurpose({
        id: row.id,
        purpose,
        amount: amountNum,
        status: row.status,
        sort_order: row.sort_order || 0
      });
      setRequestPurposes(res?.items || []);
      setToast({ open: true, message: 'Request purpose updated.', severity: 'success' });
      cancelEditRequestPurpose();
    } catch (err) {
      setToast({ open: true, message: err?.message || 'Failed to update request purpose.', severity: 'error' });
    }
  };

  const confirmDeleteRequestPurpose = (row) => {
    setDeletePurposeDialog({ open: true, row });
  };

  const closeDeleteRequestPurpose = () => {
    // Keep row data until dialog finishes closing to avoid UI flicker ("—")
    setDeletePurposeDialog(prev => ({ ...prev, open: false }));
  };

  const doDeleteRequestPurpose = async () => {
    const row = deletePurposeDialog.row;
    if (!row?.id) return closeDeleteRequestPurpose();
    try {
      await apiService.deleteRequestPurpose(row.id);
      const res = await apiService.getRequestPurposes();
      setRequestPurposes(res?.items || []);
      setToast({ open: true, message: 'Request purpose deleted.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: err?.message || 'Failed to delete request purpose.', severity: 'error' });
    } finally {
      closeDeleteRequestPurpose();
    }
  };

  const handleTabChange = (event, newValue) => {
    setActiveTab(newValue);
  };

  const closeNewKeyDialog = () => {
    setNewKeyDialog({
      open: false,
      apiKey: '',
      apiSecret: '',
      name: '',
      dialogKey: 0,
      secretMissing: false,
    });
  };

  const handleApiKeyDateSort = () => {
    setApiKeyDateSort((prev) => (prev === 'asc' ? 'desc' : 'asc'));
  };

  const copyToClipboard = async (text) => {
    try {
      await navigator.clipboard.writeText(text);
      setToast({ open: true, message: 'Copied to clipboard.', severity: 'success' });
    } catch (e) {
      setToast({ open: true, message: 'Failed to copy.', severity: 'error' });
    }
  };

  const openRevealSecretDialog = (key) => {
    setRevealSecretDialog({
      open: true,
      keyId: key.id,
      keyName: key.name || '',
      password: '',
      loading: false,
      error: '',
    });
  };

  const closeRevealSecretDialog = () => {
    setRevealSecretDialog({
      open: false,
      keyId: null,
      keyName: '',
      password: '',
      loading: false,
      error: '',
    });
  };

  const hideRevealedApiSecret = (keyId) => {
    setRevealedApiSecrets((prev) => {
      const next = { ...prev };
      delete next[keyId];
      return next;
    });
  };

  const handleApiSecretVisibility = (key) => {
    if (revealedApiSecrets[key.id]) {
      hideRevealedApiSecret(key.id);
      return;
    }
    openRevealSecretDialog(key);
  };

  const submitRevealSecret = async () => {
    const { keyId, password } = revealSecretDialog;
    if (!keyId || !password.trim()) {
      setRevealSecretDialog((prev) => ({ ...prev, error: 'Enter your account password.' }));
      return;
    }
    setRevealSecretDialog((prev) => ({ ...prev, loading: true, error: '' }));
    try {
      const res = await apiService.revealPublicApiSecret(keyId, password);
      const secret = res.api_secret || '';
      if (secret) {
        setRevealedApiSecrets((prev) => ({ ...prev, [keyId]: secret }));
      }
      closeRevealSecretDialog();
    } catch (err) {
      setRevealSecretDialog((prev) => ({
        ...prev,
        loading: false,
        error: err.message || 'Could not reveal API secret.',
      }));
    }
  };

  const togglePublicApiKeyStatus = async (row, enabled) => {
    setPublicApiBusy(true);
    try {
      await apiService.updatePublicApiKey(row.id, { enabled });
      const keysRes = await apiService.getPublicApiKeys();
      setPublicApiKeys(keysRes?.items || []);
      setToast({
        open: true,
        message: enabled ? 'API key enabled.' : 'API key disabled.',
        severity: 'success',
      });
    } catch (err) {
      setToast({
        open: true,
        message: err.message || 'Failed to update API key status.',
        severity: 'error',
      });
    } finally {
      setPublicApiBusy(false);
    }
  };

  const getApiSecretCharCount = (key, revealedSecret) => {
    if (revealedSecret) return revealedSecret.length;
    return key.api_secret_length || DEFAULT_API_SECRET_LENGTH;
  };

  const startEditApiKeyName = (key) => {
    setEditingApiKeyId(key.id);
    setEditApiKeyNameDraft(key.name || '');
  };

  const cancelEditApiKeyName = () => {
    setEditingApiKeyId(null);
    setEditApiKeyNameDraft('');
  };

  const saveEditApiKeyName = async (key) => {
    const name = (editApiKeyNameDraft || '').trim();
    if (!name) {
      setToast({ open: true, message: 'Key name cannot be empty.', severity: 'error' });
      return;
    }
    setPublicApiBusy(true);
    try {
      await apiService.updatePublicApiKey(key.id, { name });
      const keysRes = await apiService.getPublicApiKeys();
      setPublicApiKeys(keysRes?.items || []);
      cancelEditApiKeyName();
      setToast({ open: true, message: 'Key name updated.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: err.message || 'Failed to update key name.', severity: 'error' });
    } finally {
      setPublicApiBusy(false);
    }
  };

  const openApiKeyMenu = (event, row) => {
    setApiKeyMenuAnchor({ el: event.currentTarget, row });
  };

  const closeApiKeyMenu = () => {
    setApiKeyMenuAnchor({ el: null, row: null });
  };

  const confirmDeleteApiKey = (row) => {
    closeApiKeyMenu();
    setDeleteApiKeyDialog({ open: true, row });
  };

  const closeDeleteApiKeyDialog = () => {
    setDeleteApiKeyDialog((prev) => ({ ...prev, open: false }));
  };

  const doDeleteApiKey = async () => {
    const row = deleteApiKeyDialog.row;
    if (!row?.id) {
      closeDeleteApiKeyDialog();
      return;
    }
    setPublicApiBusy(true);
    try {
      await apiService.revokePublicApiKey(row.id);
      const keysRes = await apiService.getPublicApiKeys();
      setPublicApiKeys(keysRes?.items || []);
      setRevealedApiSecrets((prev) => {
        const next = { ...prev };
        delete next[row.id];
        return next;
      });
      setToast({ open: true, message: 'API key deleted.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: err.message || 'Failed to delete API key.', severity: 'error' });
    } finally {
      setPublicApiBusy(false);
      setDeleteApiKeyDialog({ open: false, row: null });
    }
  };

  const createPublicApiKey = async () => {
    setPublicApiBusy(true);
    try {
      const label = (newPublicApiKeyName || '').trim() || 'Public Website Key';
      const res = await apiService.generatePublicApiKey({
        name: label,
        api_scope: 'public_properties',
      });
      const keysRes = await apiService.getPublicApiKeys();
      const items = keysRes?.items || [];
      setPublicApiKeys(items);
      setNewPublicApiKeyName('');
      const extracted = extractCreatedApiCredentials(res, items, label);
      const { apiKey, apiSecret } = normalizeCredentialPair(extracted.apiKey, extracted.apiSecret);
      setNewKeyDialog({
        open: true,
        apiKey,
        apiSecret,
        name: extracted.name,
        dialogKey: Date.now(),
        secretMissing: !apiSecret,
      });
    } catch (err) {
      setToast({
        open: true,
        message: err.message || 'Failed to generate API key.',
        severity: 'error',
      });
    } finally {
      setPublicApiBusy(false);
    }
  };

  const renderPublicApiKeys = () => (
    <Grid container spacing={2}>
      <Grid item xs={12}>
        <Typography variant="h6" gutterBottom>
          Public API Keys
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          Each integration gets an API key and API secret (letters and numbers only). Secrets are stored as a secure hash; use the eye icon and your account password to reveal a secret in the table.
        </Typography>
      </Grid>

      <Grid item xs={12}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Grid container spacing={2} alignItems="flex-end" sx={{ mb: 2 }}>
            <Grid item xs={12} md={8}>
              <TextField
                fullWidth
                size="small"
                label="Key name"
                value={newPublicApiKeyName}
                onChange={(e) => setNewPublicApiKeyName(e.target.value)}
                placeholder="e.g. Main Website Production"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    createPublicApiKey();
                  }
                }}
              />
            </Grid>
            <Grid item xs={12} md={4}>
              <Button
                fullWidth
                variant="contained"
                disabled={publicApiBusy}
                onClick={createPublicApiKey}
              >
                Create API Key
              </Button>
            </Grid>
          </Grid>

          <TableContainer>
            <Table
              size="small"
              sx={{ tableLayout: 'fixed', width: '100%' }}
            >
              <TableHead>
                <TableRow>
                  <TableCell sx={{ width: '18%' }}>Key name</TableCell>
                  <TableCell sx={{ width: '12%' }}>
                    <TableSortLabel
                      active
                      direction={apiKeyDateSort}
                      onClick={handleApiKeyDateSort}
                    >
                      Date Created
                    </TableSortLabel>
                  </TableCell>
                  <TableCell sx={{ width: '22%' }}>API key</TableCell>
                  <TableCell sx={{ width: '30%' }}>API secret</TableCell>
                  <TableCell sx={{ width: '8%' }} align="center">Status</TableCell>
                  <TableCell sx={{ width: '10%' }} align="right" />
                </TableRow>
              </TableHead>
              <TableBody>
                {sortedPublicApiKeys.map((key) => {
                  const isActive = key.status === 'active';
                  const revealedSecret = revealedApiSecrets[key.id];
                  const secretCharCount = getApiSecretCharCount(key, revealedSecret);
                  const secretDisplay = revealedSecret || maskApiSecret(secretCharCount);
                  const isEditingName = editingApiKeyId === key.id;
                  return (
                    <TableRow key={key.id} hover>
                      <TableCell sx={{ overflow: 'hidden', verticalAlign: 'middle', ...apiKeyNameCellHoverSx }}>
                        {isEditingName ? (
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, minHeight: 32 }}>
                            <TextField
                              size="small"
                              value={editApiKeyNameDraft}
                              disabled={publicApiBusy}
                              onChange={(e) => setEditApiKeyNameDraft(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                  e.preventDefault();
                                  saveEditApiKeyName(key);
                                }
                                if (e.key === 'Escape') {
                                  e.preventDefault();
                                  cancelEditApiKeyName();
                                }
                              }}
                              autoFocus
                              sx={{ flex: 1 }}
                            />
                            <IconButton size="small" aria-label="Save name" disabled={publicApiBusy} onClick={() => saveEditApiKeyName(key)}>
                              <CheckIcon fontSize="small" />
                            </IconButton>
                            <IconButton size="small" aria-label="Cancel edit" onClick={cancelEditApiKeyName}>
                              <CloseIcon fontSize="small" />
                            </IconButton>
                          </Box>
                        ) : (
                          <Box sx={{ display: 'inline-flex', alignItems: 'center', maxWidth: '100%', verticalAlign: 'middle' }}>
                            <Typography
                              component="span"
                              variant="body2"
                              sx={{
                                fontWeight: 600,
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                                minWidth: 0,
                              }}
                            >
                              {key.name}
                            </Typography>
                            {canManage && (
                              <IconButton
                                className="api-key-name-hover-action"
                                size="small"
                                aria-label="Edit key name"
                                disabled={publicApiBusy}
                                onClick={() => startEditApiKeyName(key)}
                                sx={{ color: 'text.secondary', flexShrink: 0, ml: 0.25, width: 24, height: 24 }}
                              >
                                <EditIcon sx={{ fontSize: 16 }} />
                              </IconButton>
                            )}
                          </Box>
                        )}
                      </TableCell>
                      <TableCell sx={{ overflow: 'hidden', verticalAlign: 'middle', whiteSpace: 'nowrap' }}>
                        <Typography variant="body2" color="text.secondary">
                          {formatApiKeyDate(key.created_at)}
                        </Typography>
                      </TableCell>
                      <TableCell sx={{ overflow: 'hidden', verticalAlign: 'middle', ...apiKeyValueCellHoverSx }}>
                        <Box sx={{ display: 'inline-flex', alignItems: 'center', maxWidth: '100%' }}>
                          <Box
                            component="code"
                            sx={{
                              fontSize: '0.8rem',
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              minWidth: 0,
                            }}
                            title={key.api_key || ''}
                          >
                            {key.api_key || '—'}
                          </Box>
                          {canManage && key.api_key && (
                            <IconButton
                              className="api-key-value-hover-action"
                              size="small"
                              aria-label="Copy API key"
                              disabled={publicApiBusy}
                              onClick={() => copyToClipboard(key.api_key)}
                              sx={{ color: 'text.secondary', flexShrink: 0, ml: 0.25, width: 24, height: 24 }}
                            >
                              <ContentCopyIcon sx={{ fontSize: 16 }} />
                            </IconButton>
                          )}
                        </Box>
                      </TableCell>
                      <TableCell sx={{ overflow: 'hidden', verticalAlign: 'middle', ...apiKeySecretCellHoverSx }}>
                        <Box sx={{ display: 'inline-flex', alignItems: 'center', maxWidth: '100%' }}>
                          <Box
                            component="span"
                            title={revealedSecret ? undefined : 'Hidden'}
                            sx={{
                              ...apiKeySecretTextSx(secretCharCount),
                              color: revealedSecret ? 'text.primary' : 'text.secondary',
                            }}
                          >
                            {secretDisplay}
                          </Box>
                          {canManage && (
                            <>
                              <IconButton
                                className="api-key-secret-hover-action"
                                size="small"
                                aria-label={revealedSecret ? 'Hide API secret' : 'Reveal API secret'}
                                disabled={publicApiBusy}
                                onClick={() => handleApiSecretVisibility(key)}
                                sx={{ color: 'text.secondary', flexShrink: 0, ml: 0.25, width: 24, height: 24 }}
                              >
                                {revealedSecret ? (
                                  <VisibilityOffIcon sx={{ fontSize: 16 }} />
                                ) : (
                                  <VisibilityIcon sx={{ fontSize: 16 }} />
                                )}
                              </IconButton>
                              {revealedSecret && (
                                <IconButton
                                  className="api-key-secret-hover-action"
                                  size="small"
                                  aria-label="Copy API secret"
                                  disabled={publicApiBusy}
                                  onClick={() => copyToClipboard(revealedSecret)}
                                  sx={{ color: 'text.secondary', flexShrink: 0, ml: 0.25, width: 24, height: 24 }}
                                >
                                  <ContentCopyIcon sx={{ fontSize: 16 }} />
                                </IconButton>
                              )}
                            </>
                          )}
                        </Box>
                      </TableCell>
                      <TableCell align="center" sx={{ verticalAlign: 'middle' }}>
                        <Switch
                          size="small"
                          checked={isActive}
                          disabled={publicApiBusy}
                          onChange={(e) => togglePublicApiKeyStatus(key, e.target.checked)}
                        />
                      </TableCell>
                      <TableCell align="right" sx={{ verticalAlign: 'middle', width: 48, p: 0.5 }}>
                        {canManage && (
                          <IconButton
                            size="small"
                            aria-label="API key actions"
                            disabled={publicApiBusy}
                            onClick={(e) => openApiKeyMenu(e, key)}
                            sx={{ color: 'text.secondary' }}
                          >
                            <MoreVertIcon fontSize="small" />
                          </IconButton>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
                {(!publicApiKeys || publicApiKeys.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={6} align="center" sx={{ py: 4, color: 'text.secondary' }}>
                      No API keys yet. Create one to allow external property search.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
          <Menu
            anchorEl={apiKeyMenuAnchor.el}
            open={Boolean(apiKeyMenuAnchor.el)}
            onClose={closeApiKeyMenu}
          >
            <MenuItem
              onClick={() => confirmDeleteApiKey(apiKeyMenuAnchor.row)}
              sx={{ color: 'error.main' }}
            >
              Delete
            </MenuItem>
          </Menu>
        </Paper>
      </Grid>

      <Grid item xs={12}>
        <Alert severity="info" variant="outlined">
          <Typography variant="subtitle2" gutterBottom>
            API usage
          </Typography>
          <Typography variant="body2" component="div">
            <Box component="code" sx={{ display: 'block', mb: 0.5 }}>
              GET /wp-json/assessor/v1/public/properties
            </Box>
            Send <Box component="code" display="inline">X-API-Key</Box> and <Box component="code" display="inline">X-API-Secret</Box> headers (or <Box component="code" display="inline">api_key</Box> and <Box component="code" display="inline">api_secret</Box> query parameters).
          </Typography>
        </Alert>
      </Grid>
    </Grid>
  );

  const loadSyncConfig = async () => {
    setSyncConfigLoading(true);
    setSyncConfigError(null);
    try {
      const data = await apiService.getSyncConfig();
      setSyncConfig(data);
    } catch (e) {
      console.error('Refresh Sync config error:', e);
      setSyncConfigError(e.message || 'Unknown error');
      setSyncConfig(null);
    }
    setSyncConfigLoading(false);
  };

  const handleGenerateToken = async () => {
    setTokenGenerating(true);
    setSyncSettingsMsg({ type: '', text: '' });
    try {
      const res = await apiService.generateSyncToken();
      setGeneratedToken(res.token || '');
    } catch (e) {
      setSyncSettingsMsg({ type: 'error', text: e.message || 'Failed to generate token.' });
    }
    setTokenGenerating(false);
  };

  const handleSaveToken = async (tokenToSave) => {
    if (!tokenToSave) return;
    setTokenSaving(true);
    setSyncSettingsMsg({ type: '', text: '' });
    try {
      await apiService.saveSyncToken(tokenToSave);
      setSyncSettingsMsg({ type: 'success', text: 'Token saved to wp-config.php! Reload the server for changes to take effect.' });
      loadSyncConfig();
    } catch (e) {
      setSyncSettingsMsg({ type: 'error', text: e.message || 'Failed to save token.' });
    }
    setTokenSaving(false);
  };

  const renderSyncSettings = () => {
    const activeToken = generatedToken || pastedToken;
    const configSnippet = activeToken
      ? `// Local wp-config.php\ndefine('ASSESSOR_IS_LOCAL_BUILD', true);\ndefine('ASSESSOR_LIVE_SITE_URL', 'https://your-live-domain.com');\ndefine('ASSESSOR_SYNC_TOKEN', '${activeToken}');\n\n// Live wp-config.php\ndefine('ASSESSOR_SYNC_TOKEN', '${activeToken}');`
      : `// Local wp-config.php\ndefine('ASSESSOR_IS_LOCAL_BUILD', true);\ndefine('ASSESSOR_LIVE_SITE_URL', 'https://your-live-domain.com');\ndefine('ASSESSOR_SYNC_TOKEN', 'YOUR_TOKEN_HERE');\n\n// Live wp-config.php\ndefine('ASSESSOR_SYNC_TOKEN', 'YOUR_TOKEN_HERE');`;

    return (
      <Grid container spacing={3}>
        {/* Status Panel */}
        <Grid item xs={12}>
          <Typography variant="h6" gutterBottom>Sync Status</Typography>
          {syncConfigLoading ? (
            <CircularProgress size={24} />
          ) : syncConfig ? (
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Grid container spacing={2}>
                <Grid item xs={12} sm={6} md={3}>
                  <Typography variant="caption" color="text.secondary">Mode</Typography>
                  <Box sx={{ mt: 0.5 }}>
                    <Box component="span" sx={{ display: 'inline-block', px: 1.5, py: 0.5, borderRadius: 2, fontSize: '0.8rem', fontWeight: 600, bgcolor: syncConfig.is_local_build ? 'primary.light' : 'grey.300', color: syncConfig.is_local_build ? 'primary.contrastText' : 'text.secondary' }}>
                      {syncConfig.is_local_build ? 'Local Build' : 'Live Server'}
                    </Box>
                  </Box>
                </Grid>
                <Grid item xs={12} sm={6} md={3}>
                  <Typography variant="caption" color="text.secondary">Sync Token</Typography>
                  <Box sx={{ mt: 0.5 }}>
                    <Box component="span" sx={{ display: 'inline-block', px: 1.5, py: 0.5, borderRadius: 2, fontSize: '0.8rem', fontWeight: 600, bgcolor: syncConfig.has_token ? 'success.light' : 'error.light', color: syncConfig.has_token ? 'success.contrastText' : 'error.contrastText' }}>
                      {syncConfig.has_token ? '✓ Configured' : '✗ Not Set'}
                    </Box>
                  </Box>
                </Grid>
                {syncConfig.live_url && (
                  <Grid item xs={12} sm={6} md={3}>
                    <Typography variant="caption" color="text.secondary">Live URL</Typography>
                    <Typography variant="body2" sx={{ mt: 0.5, wordBreak: 'break-all', fontSize: '0.8rem' }}>{syncConfig.live_url}</Typography>
                  </Grid>
                )}
                <Grid item xs={12} sm={6} md={3}>
                  <Typography variant="caption" color="text.secondary">Last Push / Pull</Typography>
                  <Typography variant="body2" sx={{ mt: 0.5, fontSize: '0.8rem' }}>
                    {syncConfig.last_push ? `↑ ${syncConfig.last_push}` : '↑ Never'}<br />
                    {syncConfig.last_pull ? `↓ ${syncConfig.last_pull}` : '↓ Never'}
                  </Typography>
                </Grid>
              </Grid>
            </Paper>
          ) : (
            <Alert severity="warning">
              Could not load sync config. Error: {syncConfigError || 'Unknown API error'}.
              Please check the browser console or server logs.
            </Alert>
          )}
          <Button size="small" variant="text" onClick={loadSyncConfig} disabled={syncConfigLoading} sx={{ mt: 1 }}>
            Refresh Status
          </Button>
        </Grid>

        <Grid item xs={12}><Divider /></Grid>

        {/* Token Generator */}
        <Grid item xs={12}>
          <Typography variant="h6" gutterBottom>Sync Token Management</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Both servers must share the same token. <strong>Generate it on one server, then paste and save it on the other.</strong>
          </Typography>

          {/* Generate section */}
          <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
            <Typography variant="subtitle2" gutterBottom>Generate a New Token</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              Creates a 64-character cryptographically secure hex token. It will <strong>not</strong> be saved automatically — click "Save to This Server" below.
            </Typography>
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-start', flexWrap: 'wrap' }}>
              <TextField
                size="small"
                value={generatedToken}
                placeholder="Click Generate to create a token..."
                InputProps={{ readOnly: true, sx: { fontFamily: 'monospace', fontSize: '0.75rem' } }}
                sx={{ flex: 1, minWidth: 260 }}
              />
              <IconButton
                size="small"
                disabled={!generatedToken}
                title="Copy token"
                onClick={() => { navigator.clipboard.writeText(generatedToken); setToast({ open: true, message: 'Token copied!', severity: 'success' }); }}
              >
                <ContentCopyIcon fontSize="small" />
              </IconButton>
            </Box>
            <Box sx={{ display: 'flex', gap: 1, mt: 1.5, flexWrap: 'wrap' }}>
              <Button variant="outlined" size="small" onClick={handleGenerateToken} disabled={tokenGenerating} startIcon={tokenGenerating ? <CircularProgress size={16} /> : null}>
                {tokenGenerating ? 'Generating...' : 'Generate New Token'}
              </Button>
              <Button
                variant="contained"
                size="small"
                color="success"
                disabled={!generatedToken || tokenSaving}
                onClick={() => handleSaveToken(generatedToken)}
                startIcon={tokenSaving ? <CircularProgress size={16} color="inherit" /> : null}
              >
                {tokenSaving ? 'Saving...' : 'Save to This Server'}
              </Button>
            </Box>
          </Paper>

          {/* Paste section */}
          <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
            <Typography variant="subtitle2" gutterBottom>Paste Token from Another Server</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              If the token was generated on another server, paste it here and save it to this server's wp-config.php.
            </Typography>
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-start', flexWrap: 'wrap' }}>
              <TextField
                size="small"
                value={pastedToken}
                onChange={(e) => setPastedToken(e.target.value.trim())}
                placeholder="Paste token here..."
                InputProps={{ sx: { fontFamily: 'monospace', fontSize: '0.75rem' } }}
                sx={{ flex: 1, minWidth: 260 }}
              />
              <Button
                variant="contained"
                size="small"
                color="success"
                disabled={!pastedToken || pastedToken.length < 32 || tokenSaving}
                onClick={() => handleSaveToken(pastedToken)}
                startIcon={tokenSaving ? <CircularProgress size={16} color="inherit" /> : null}
              >
                {tokenSaving ? 'Saving...' : 'Save to This Server'}
              </Button>
            </Box>
          </Paper>

          {/* Status messages */}
          {syncSettingsMsg.text && (
            <Alert severity={syncSettingsMsg.type || 'info'} sx={{ mt: 1 }} onClose={() => setSyncSettingsMsg({ type: '', text: '' })}>
              {syncSettingsMsg.text}
            </Alert>
          )}
        </Grid>

        <Grid item xs={12}><Divider /></Grid>

        {/* Manual Instructions */}
        <Grid item xs={12}>
          <Typography variant="h6" gutterBottom>Manual wp-config.php Snippet</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
            If the automatic save doesn't work (e.g., file permissions), add these lines manually to each server's wp-config.php:
          </Typography>
          <Paper variant="outlined" sx={{ p: 2, bgcolor: 'grey.50', position: 'relative' }}>
            <Box component="pre" sx={{ fontFamily: 'monospace', fontSize: '0.78rem', whiteSpace: 'pre-wrap', wordBreak: 'break-all', m: 0, color: 'text.primary' }}>
              {configSnippet}
            </Box>
            <IconButton
              size="small"
              sx={{ position: 'absolute', top: 8, right: 8 }}
              title="Copy snippet"
              onClick={() => { navigator.clipboard.writeText(configSnippet); setToast({ open: true, message: 'Snippet copied!', severity: 'success' }); }}
            >
              <ContentCopyIcon fontSize="small" />
            </IconButton>
          </Paper>
          <Alert severity="info" sx={{ mt: 2 }}>
            After saving the token, changes take effect immediately on most servers. If it doesn't work right away, wait 1-2 minutes for your server's PHP cache (OPcache) to refresh, or restart your local server if using XAMPP/WAMP.
          </Alert>
        </Grid>
      </Grid>
    );
  };

  const handleEtracsPull = async () => {
    setEtracsPulling(true);
    try {
      const res = await apiService.pullEtracsSync();
      setToast({
        open: true,
        message: `ETRACS Pull Successful! Stats: Entities(${res.stats?.entity || 0}) RealProperty(${res.stats?.real_property || 0}) RPU(${res.stats?.rpu || 0}) FAAS(${res.stats?.faas || 0})`,
        severity: 'success'
      });
    } catch (e) {
      setToast({ open: true, message: e.message || 'Failed to pull ETRACS data.', severity: 'error' });
    } finally {
      setEtracsPulling(false);
      setSyncProgress('Complete');
    }
  };

  const renderEtracsSyncSettings = () => {
    return (
      <Grid container spacing={3}>
        <Grid item xs={12}>
          <Typography variant="h6" gutterBottom>ETRACS Database Connection</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Configure the connection to the external ETRACS MySQL database to import entities, real properties, RPUs, and FAAS records.
          </Typography>

          <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
            <Grid container spacing={2}>
              <Grid item xs={12} sm={8}>
                <TextField
                  fullWidth
                  size="small"
                  label="Database Host"
                  value={form.assessor_etracs_db_host || ''}
                  onChange={(e) => handleChange('assessor_etracs_db_host', e.target.value)}
                />
              </Grid>
              <Grid item xs={12} sm={4}>
                <TextField
                  fullWidth
                  size="small"
                  label="Port"
                  value={form.assessor_etracs_db_port || ''}
                  onChange={(e) => handleChange('assessor_etracs_db_port', e.target.value)}
                />
              </Grid>
              <Grid item xs={12} sm={6}>
                <TextField
                  fullWidth
                  size="small"
                  label="Database User"
                  value={form.assessor_etracs_db_user || ''}
                  onChange={(e) => handleChange('assessor_etracs_db_user', e.target.value)}
                />
              </Grid>
              <Grid item xs={12} sm={6}>
                <TextField
                  fullWidth
                  size="small"
                  label="Database Password"
                  type="password"
                  value={form.assessor_etracs_db_password || ''}
                  onChange={(e) => handleChange('assessor_etracs_db_password', e.target.value)}
                />
              </Grid>
              <Grid item xs={12}>
                <TextField
                  fullWidth
                  size="small"
                  label="Database Name"
                  value={form.assessor_etracs_db_name || ''}
                  onChange={(e) => handleChange('assessor_etracs_db_name', e.target.value)}
                />
              </Grid>
            </Grid>
            <Box sx={{ mt: 2, textAlign: 'right' }}>
              <Button variant="contained" onClick={handleSave}>Save Connection Settings</Button>
            </Box>
          </Paper>
        </Grid>

        <Grid item xs={12}><Divider /></Grid>

        <Grid item xs={12}>
          <Typography variant="h6" gutterBottom>Data Synchronization</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Pull the latest real property and entity data from ETRACS. This operation may take some time depending on the database size.
          </Typography>
          <Button
            variant="contained"
            color="primary"
            onClick={handleEtracsPull}
            disabled={etracsPulling}
            startIcon={etracsPulling ? <CircularProgress size={16} color="inherit" /> : null}
          >
            {etracsPulling ? 'Pulling Data...' : 'Pull Latest Data'}
          </Button>

          {(etracsPulling || syncProgress === 'Complete') && (
            <Paper variant="outlined" sx={{ p: 2, mt: 2, bgcolor: 'grey.900', color: 'success.main', fontFamily: 'monospace' }}>
              <Typography variant="body2" sx={{ fontFamily: 'monospace', fontSize: '0.85rem' }}>
                $ {syncProgress}
              </Typography>
            </Paper>
          )}
        </Grid>
      </Grid>
    );
  };

  const handleCancelSettings = () => {
    if (pendingLogoPreview) {
      try { URL.revokeObjectURL(pendingLogoPreview); } catch (e) { }
    }
    if (pendingHeaderPhotoPreview) {
      try { URL.revokeObjectURL(pendingHeaderPhotoPreview); } catch (e) { }
    }
    setPendingLogoFile(null);
    setPendingLogoPreview('');
    setPendingHeaderPhotoFile(null);
    setPendingHeaderPhotoPreview('');
    setForm(originalForm);
  };

  const renderGeneralSettings = () => (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}>
      {/* 1. Appearance & Branding */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, color: 'text.primary', mb: 0.25 }}>
            Appearance & Branding
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Manage the application logo and document header banner displayed across official certificates and exports.
          </Typography>

          <Grid container spacing={2.5}>
            {/* Logo Section */}
            <Grid item xs={12} md={6}>
              <Box sx={{ p: 2, borderRadius: 1.5, border: '1px solid', borderColor: 'divider', height: '100%', display: 'flex', flexDirection: 'column' }}>
                <Typography variant="body2" sx={{ fontWeight: 600, mb: 1.5 }}>
                  Application Logo
                </Typography>
                <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', mb: 2 }}>
                  <Paper
                    variant="outlined"
                    sx={{
                      width: 80,
                      height: 80,
                      borderRadius: 1.5,
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      bgcolor: 'background.default',
                      flexShrink: 0,
                      overflow: 'hidden',
                      p: 0.5
                    }}
                  >
                    {pendingLogoPreview ? (
                      <img src={pendingLogoPreview} alt="Logo Preview" style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }} />
                    ) : form.app_logo_url ? (
                      <img src={form.app_logo_url} alt="Logo" style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain' }} />
                    ) : (
                      <Typography variant="caption" color="text.secondary" align="center">No logo</Typography>
                    )}
                  </Paper>
                  <Box sx={{ flex: 1 }}>
                    <Button variant="outlined" size="small" component="label" sx={{ mb: 0.75, textTransform: 'none' }}>
                      Choose Image…
                      <input type="file" accept="image/*" hidden onChange={handleLogoUpload} />
                    </Button>
                    <Typography variant="caption" color="text.secondary" display="block">
                      Square PNG/JPG up to 2MB.
                    </Typography>
                    {pendingLogoFile && (
                      <Chip
                        size="small"
                        color="warning"
                        label={`Pending save: ${pendingLogoFile.name}`}
                        sx={{ mt: 0.75, fontSize: '0.75rem', height: 22 }}
                      />
                    )}
                  </Box>
                </Box>
                <TextField
                  fullWidth
                  size="small"
                  label="Logo Image URL"
                  value={form.app_logo_url || ''}
                  onChange={(e) => handleChange('app_logo_url', e.target.value)}
                  placeholder="https://..."
                  helperText="Direct image URL link"
                  sx={{ mt: 'auto' }}
                />
              </Box>
            </Grid>

            {/* Header Photo Section */}
            <Grid item xs={12} md={6}>
              <Box sx={{ p: 2, borderRadius: 1.5, border: '1px solid', borderColor: 'divider', height: '100%', display: 'flex', flexDirection: 'column' }}>
                <Typography variant="body2" sx={{ fontWeight: 600, mb: 1.5 }}>
                  Header Photo
                </Typography>
                <Box sx={{ mb: 2 }}>
                  <Paper
                    variant="outlined"
                    sx={{
                      width: '100%',
                      height: 52,
                      borderRadius: 1.5,
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      bgcolor: 'background.default',
                      overflow: 'hidden',
                      p: 0.5
                    }}
                  >
                    {pendingHeaderPhotoPreview ? (
                      <img src={pendingHeaderPhotoPreview} alt="Header Banner Preview" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                    ) : form.header_photo_url ? (
                      <img src={form.header_photo_url} alt="Header Banner" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                    ) : (
                      <Typography variant="caption" color="text.secondary">No header banner uploaded</Typography>
                    )}
                  </Paper>
                  <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mt: 1 }}>
                    <Button variant="outlined" size="small" component="label" sx={{ textTransform: 'none' }}>
                      Choose Banner…
                      <input type="file" accept="image/*" hidden onChange={handleHeaderPhotoUpload} />
                    </Button>
                    {pendingHeaderPhotoFile && (
                      <Chip
                        size="small"
                        color="warning"
                        label={`Pending save: ${pendingHeaderPhotoFile.name}`}
                        sx={{ fontSize: '0.75rem', height: 22 }}
                      />
                    )}
                  </Box>
                </Box>
                <TextField
                  fullWidth
                  size="small"
                  label="Header Banner URL"
                  value={form.header_photo_url || ''}
                  onChange={(e) => handleChange('header_photo_url', e.target.value)}
                  placeholder="https://..."
                  helperText="Recommended wide ratio (~8:1)"
                  sx={{ mt: 'auto' }}
                />
              </Box>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* 2. Office & Jurisdiction Information */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, color: 'text.primary', mb: 0.25 }}>
            Office & Jurisdiction Information
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Official local government unit identifiers and municipality jurisdictional settings.
          </Typography>

          <Grid container spacing={2}>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="Province"
                value={(form.header_province || '').toUpperCase()}
                onChange={(e) => handleChange('header_province', e.target.value.toUpperCase())}
                placeholder="BUKIDNON"
              />
            </Grid>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="Municipality"
                value={(form.header_municipality || '').toUpperCase()}
                onChange={(e) => handleChange('header_municipality', e.target.value.toUpperCase())}
                placeholder="KITAOTAO"
              />
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                size="small"
                label="Office Department Header"
                value={(form.header_office || '').toUpperCase()}
                onChange={(e) => handleChange('header_office', e.target.value.toUpperCase())}
                placeholder="OFFICE OF THE MUNICIPAL ASSESSOR"
                helperText="Appears on formal municipal tax declaration forms and certificates"
              />
            </Grid>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="LGU Base PIN"
                value={form.lgu_pin || ''}
                onChange={(e) => handleChange('lgu_pin', e.target.value)}
                helperText="Base Property Identification Number prefix"
                placeholder="059-10"
              />
            </Grid>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="User ID Prefix"
                value={form.municipality_prefix ? form.municipality_prefix.toUpperCase() : ''}
                onChange={(e) => handleChange('municipality_prefix', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 3))}
                helperText="Max 3 alphanumeric characters (e.g. KIT)"
                placeholder="KIT"
              />
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* 3. Print Signatories & Authority */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, color: 'text.primary', mb: 0.25 }}>
            Print Signatories & Authority
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Default verifiers and municipal assessors endorsing official certificates and assessment rolls.
          </Typography>

          <Grid container spacing={2.5}>
            {/* Municipal Assessor Sub-section */}
            <Grid item xs={12} md={6}>
              <Box sx={{ p: 2, borderRadius: 1.5, border: '1px solid', borderColor: 'divider', height: '100%' }}>
                <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main', mb: 1.5 }}>
                  Municipal Assessor
                </Typography>
                <Grid container spacing={2}>
                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Municipal Assessor Name"
                      value={(form.municipal_assessor_name || '').toUpperCase()}
                      onChange={(e) => handleChange('municipal_assessor_name', e.target.value.toUpperCase())}
                      placeholder="FULL NAME"
                    />
                  </Grid>
                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Official Title / Designation"
                      value={(form.municipal_assessor_title || '').toUpperCase()}
                      onChange={(e) => handleChange('municipal_assessor_title', e.target.value.toUpperCase())}
                      placeholder="MUNICIPAL ASSESSOR / ACTING MUNICIPAL ASSESSOR"
                    />
                  </Grid>
                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="License Number"
                      value={(form.municipal_assessor_license || '').toUpperCase()}
                      onChange={(e) => handleChange('municipal_assessor_license', e.target.value.toUpperCase())}
                      placeholder="PRC LICENSE NO."
                    />
                  </Grid>
                  <Grid item xs={12} sm={6}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Suffix (Degrees)"
                      value={(form.municipal_assessor_suffix || '').toUpperCase()}
                      onChange={(e) => handleChange('municipal_assessor_suffix', e.target.value.toUpperCase())}
                      placeholder="MMREM, REA, REB, LPT"
                    />
                  </Grid>
                </Grid>
              </Box>
            </Grid>

            {/* Verifier / Signatory Sub-section */}
            <Grid item xs={12} md={6}>
              <Box sx={{ p: 2, borderRadius: 1.5, border: '1px solid', borderColor: 'divider', height: '100%' }}>
                <Typography variant="body2" sx={{ fontWeight: 700, color: 'primary.main', mb: 1.5 }}>
                  Verifier / Signatory
                </Typography>
                <Grid container spacing={2}>
                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Verifier Signatory Name"
                      value={(form.verifier_signatory_name || '').toUpperCase()}
                      onChange={(e) => handleChange('verifier_signatory_name', e.target.value.toUpperCase())}
                      placeholder="FULL NAME"
                    />
                  </Grid>
                  <Grid item xs={12}>
                    <TextField
                      fullWidth
                      size="small"
                      label="Verifier Signatory Title"
                      value={(form.verifier_signatory_title || '').toUpperCase()}
                      onChange={(e) => handleChange('verifier_signatory_title', e.target.value.toUpperCase())}
                      placeholder="ASSESSMENT CLERK / LAOO"
                    />
                  </Grid>
                </Grid>
              </Box>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* 4. Session & Security Controls */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, color: 'text.primary', mb: 0.25 }}>
            Session & Security Controls
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Automatically sign users out after a period of inactivity.
          </Typography>

          <Box sx={{ maxWidth: 360 }}>
            <TextField
              fullWidth
              label="Automatic Logout"
              type="number"
              value={form.afk_timeout ?? 30}
              onChange={(e) => handleAfkTimeoutChange(e.target.value)}
              helperText={`Automatically logs out inactive users after ${form.afk_timeout || 30} minutes (5–480 min).`}
              inputProps={{ min: 5, max: 480 }}
              size="small"
              error={form.afk_timeout !== '' && (form.afk_timeout < 5 || form.afk_timeout > 480)}
            />
          </Box>
        </CardContent>
      </Card>

      {/* 5. Advanced Features */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 2.5 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, color: 'text.primary', mb: 0.25 }}>
            Advanced Features
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Configure extended integration features and legacy database services.
          </Typography>

          <Box sx={{ bgcolor: 'action.hover', p: 2, borderRadius: 1.5, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 2 }}>
            <Box>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>
                ETRACS Integration
              </Typography>
              <Typography variant="caption" color="text.secondary" display="block">
                Enable ETRACS-related properties, taxpayers, and navigation features.
              </Typography>
            </Box>
            <Switch
              checked={Number(form.enable_etracs_features) === 1}
              onChange={(e) => handleChange('enable_etracs_features', e.target.checked ? 1 : 0)}
              color="primary"
            />
          </Box>
        </CardContent>
      </Card>
    </Box>
  );

  const renderDataManagement = () => (
    <Grid container spacing={3}>
      {/* Property Types Card */}
      <Grid item xs={12} md={6} lg={4}>
        <Card variant="outlined" sx={{ borderRadius: 2, height: '100%', display: 'flex', flexDirection: 'column' }}>
          <CardContent sx={{ p: 2.5, flexGrow: 1, display: 'flex', flexDirection: 'column' }}>
            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
              <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                Property Types
              </Typography>
              <Chip size="small" label={`${propertyTypes?.length || 0} types`} sx={{ height: 22, fontSize: '0.75rem' }} />
            </Box>
            <Typography variant="caption" color="text.secondary" sx={{ mb: 2 }}>
              Drag items to reorder priority in dropdowns.
            </Typography>

            <Paper variant="outlined" sx={{ p: 1.5, mb: 2, borderRadius: 1.5, bgcolor: 'background.default' }}>
              <Grid container spacing={1}>
                <Grid item xs={4}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Code"
                    placeholder="LAND"
                    value={newType.code}
                    onChange={(e) => setNewType({ ...newType, code: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPropertyType(); } }}
                  />
                </Grid>
                <Grid item xs={8}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Type Name"
                    placeholder="Land"
                    value={newType.name}
                    onChange={(e) => setNewType({ ...newType, name: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPropertyType(); } }}
                  />
                </Grid>
                <Grid item xs={12}>
                  <Button fullWidth variant="contained" size="small" onClick={addPropertyType} sx={{ textTransform: 'none', py: 0.75 }}>
                    + Add Property Type
                  </Button>
                </Grid>
              </Grid>
            </Paper>

            <List dense sx={{ flexGrow: 1, overflowY: 'auto', maxHeight: 380, p: 0 }}>
              {(propertyTypes || []).map((t, index) => (
                <ListItem
                  key={t.id}
                  draggable
                  onDragStart={() => handleDragStart('propertyTypes', index)}
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={() => handleDrop('propertyTypes', index)}
                  secondaryAction={
                    <Box sx={{ display: 'flex', alignItems: 'center' }}>
                      <Switch
                        size="small"
                        checked={t.status === 'active'}
                        onChange={async (e) => {
                          try {
                            const updated = await apiService.savePropertyType({ id: t.id, code: t.code, name: t.name, status: e.target.checked ? 'active' : 'disabled', sort_order: t.sort_order || 0 });
                            setPropertyTypes(updated?.items || []);
                            setToast({ open: true, message: 'Property type updated.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to update property type.', severity: 'error' });
                          }
                        }}
                      />
                      <IconButton
                        size="small"
                        edge="end"
                        aria-label="delete"
                        onClick={async () => {
                          try {
                            await apiService.deletePropertyType(t.id);
                            const res = await apiService.getPropertyTypes();
                            setPropertyTypes(res?.items || []);
                            setToast({ open: true, message: 'Property type deleted.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to delete property type.', severity: 'error' });
                          }
                        }}
                        sx={{ ml: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Box>
                  }
                  sx={{
                    border: '1px solid',
                    borderColor: 'divider',
                    borderRadius: 1,
                    mb: 1,
                    px: 1,
                    py: 0.75,
                    bgcolor: 'background.paper',
                    '&:hover': { bgcolor: 'action.hover' }
                  }}
                >
                  <ListItemIcon sx={{ minWidth: 26, cursor: 'grab', color: 'text.secondary' }}>
                    <DragIndicatorIcon fontSize="small" />
                  </ListItemIcon>
                  <ListItemText
                    primary={
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Chip
                          label={t.code}
                          size="small"
                          sx={{
                            height: 20,
                            fontSize: '0.7rem',
                            fontWeight: 700,
                            fontFamily: 'monospace',
                            bgcolor: 'action.hover',
                            borderRadius: 0.75
                          }}
                        />
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {t.name}
                        </Typography>
                      </Box>
                    }
                    secondary={t.status === 'active' ? 'Active' : 'Disabled'}
                    secondaryTypographyProps={{ variant: 'caption', color: t.status === 'active' ? 'text.secondary' : 'text.disabled' }}
                    sx={{ my: 0, pr: 8 }}
                  />
                </ListItem>
              ))}
            </List>
          </CardContent>
        </Card>
      </Grid>

      {/* General Classes Card */}
      <Grid item xs={12} md={6} lg={4}>
        <Card variant="outlined" sx={{ borderRadius: 2, height: '100%', display: 'flex', flexDirection: 'column' }}>
          <CardContent sx={{ p: 2.5, flexGrow: 1, display: 'flex', flexDirection: 'column' }}>
            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
              <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                General Classes
              </Typography>
              <Chip size="small" label={`${generalClasses?.length || 0} classes`} sx={{ height: 22, fontSize: '0.75rem' }} />
            </Box>
            <Typography variant="caption" color="text.secondary" sx={{ mb: 2 }}>
              Classification categories for tax assessment computation.
            </Typography>

            <Paper variant="outlined" sx={{ p: 1.5, mb: 2, borderRadius: 1.5, bgcolor: 'background.default' }}>
              <Grid container spacing={1}>
                <Grid item xs={4}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Code"
                    placeholder="RES"
                    value={newClass.code}
                    onChange={(e) => setNewClass({ ...newClass, code: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addGeneralClass(); } }}
                  />
                </Grid>
                <Grid item xs={8}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Class Name"
                    placeholder="Residential"
                    value={newClass.name}
                    onChange={(e) => setNewClass({ ...newClass, name: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addGeneralClass(); } }}
                  />
                </Grid>
                <Grid item xs={12}>
                  <Button fullWidth variant="contained" size="small" onClick={addGeneralClass} sx={{ textTransform: 'none', py: 0.75 }}>
                    + Add General Class
                  </Button>
                </Grid>
              </Grid>
            </Paper>

            <List dense sx={{ flexGrow: 1, overflowY: 'auto', maxHeight: 380, p: 0 }}>
              {(generalClasses || []).map((c, index) => (
                <ListItem
                  key={c.id}
                  draggable
                  onDragStart={() => handleDragStart('generalClasses', index)}
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={() => handleDrop('generalClasses', index)}
                  secondaryAction={
                    <Box sx={{ display: 'flex', alignItems: 'center' }}>
                      <Switch
                        size="small"
                        checked={c.status === 'active'}
                        onChange={async (e) => {
                          try {
                            const updated = await apiService.saveGeneralClass({ id: c.id, code: c.code, name: c.name, status: e.target.checked ? 'active' : 'disabled', sort_order: c.sort_order || 0 });
                            setGeneralClasses(updated?.items || []);
                            setToast({ open: true, message: 'General class updated.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to update general class.', severity: 'error' });
                          }
                        }}
                      />
                      <IconButton
                        size="small"
                        edge="end"
                        aria-label="delete"
                        onClick={async () => {
                          try {
                            await apiService.deleteGeneralClass(c.id);
                            const res = await apiService.getGeneralClasses();
                            setGeneralClasses(res?.items || []);
                            setToast({ open: true, message: 'General class deleted.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to delete general class.', severity: 'error' });
                          }
                        }}
                        sx={{ ml: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Box>
                  }
                  sx={{
                    border: '1px solid',
                    borderColor: 'divider',
                    borderRadius: 1,
                    mb: 1,
                    px: 1,
                    py: 0.75,
                    bgcolor: 'background.paper',
                    '&:hover': { bgcolor: 'action.hover' }
                  }}
                >
                  <ListItemIcon sx={{ minWidth: 26, cursor: 'grab', color: 'text.secondary' }}>
                    <DragIndicatorIcon fontSize="small" />
                  </ListItemIcon>
                  <ListItemText
                    primary={
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Chip
                          label={c.code}
                          size="small"
                          sx={{
                            height: 20,
                            fontSize: '0.7rem',
                            fontWeight: 700,
                            fontFamily: 'monospace',
                            bgcolor: 'action.hover',
                            borderRadius: 0.75
                          }}
                        />
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {c.name}
                        </Typography>
                      </Box>
                    }
                    secondary={c.status === 'active' ? 'Active' : 'Disabled'}
                    secondaryTypographyProps={{ variant: 'caption', color: c.status === 'active' ? 'text.secondary' : 'text.disabled' }}
                    sx={{ my: 0, pr: 8 }}
                  />
                </ListItem>
              ))}
            </List>
          </CardContent>
        </Card>
      </Grid>

      {/* Barangays Card */}
      <Grid item xs={12} md={12} lg={4}>
        <Card variant="outlined" sx={{ borderRadius: 2, height: '100%', display: 'flex', flexDirection: 'column' }}>
          <CardContent sx={{ p: 2.5, flexGrow: 1, display: 'flex', flexDirection: 'column' }}>
            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
              <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                Barangays
              </Typography>
              <Chip size="small" label={`${locations?.length || 0} barangays`} sx={{ height: 22, fontSize: '0.75rem' }} />
            </Box>
            <Typography variant="caption" color="text.secondary" sx={{ mb: 2 }}>
              Barangay administrative units and PIN mapping.
            </Typography>

            <Paper variant="outlined" sx={{ p: 1.5, mb: 2, borderRadius: 1.5, bgcolor: 'background.default' }}>
              <Grid container spacing={1}>
                <Grid item xs={4}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Code"
                    placeholder="001"
                    value={newLocation.code}
                    onChange={(e) => setNewLocation({ ...newLocation, code: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addLocation(); } }}
                  />
                </Grid>
                <Grid item xs={8}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Barangay Name"
                    placeholder="Poblacion"
                    value={newLocation.name}
                    onChange={(e) => setNewLocation({ ...newLocation, name: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addLocation(); } }}
                  />
                </Grid>
                <Grid item xs={12}>
                  <TextField
                    fullWidth
                    size="small"
                    label="Barangay PIN"
                    placeholder="0001"
                    value={newLocation.pin}
                    onChange={(e) => setNewLocation({ ...newLocation, pin: e.target.value.toUpperCase() })}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addLocation(); } }}
                  />
                </Grid>
                <Grid item xs={12}>
                  <Button fullWidth variant="contained" size="small" onClick={addLocation} sx={{ textTransform: 'none', py: 0.75 }}>
                    + Add Barangay
                  </Button>
                </Grid>
              </Grid>
            </Paper>

            <List dense sx={{ flexGrow: 1, overflowY: 'auto', maxHeight: 380, p: 0 }}>
              {(locations || []).map((l, index) => (
                <ListItem
                  key={l.id}
                  draggable
                  onDragStart={() => handleDragStart('locations', index)}
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={() => handleDrop('locations', index)}
                  secondaryAction={
                    <Box sx={{ display: 'flex', alignItems: 'center' }}>
                      <Switch
                        size="small"
                        checked={l.status === 'active'}
                        onChange={async (e) => {
                          try {
                            const updated = await apiService.saveLocation({ id: l.id, code: l.code, name: l.name, pin: l.pin, status: e.target.checked ? 'active' : 'disabled', sort_order: l.sort_order || 0 });
                            setLocations(updated?.items || []);
                            setToast({ open: true, message: 'Barangay updated.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to update barangay.', severity: 'error' });
                          }
                        }}
                      />
                      <IconButton
                        size="small"
                        edge="end"
                        aria-label="delete"
                        onClick={async () => {
                          try {
                            await apiService.deleteLocation(l.id);
                            const res = await apiService.getLocations();
                            setLocations(res?.items || []);
                            setToast({ open: true, message: 'Barangay deleted.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to delete barangay.', severity: 'error' });
                          }
                        }}
                        sx={{ ml: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Box>
                  }
                  sx={{
                    border: '1px solid',
                    borderColor: 'divider',
                    borderRadius: 1,
                    mb: 1,
                    px: 1,
                    py: 0.75,
                    bgcolor: 'background.paper',
                    '&:hover': { bgcolor: 'action.hover' }
                  }}
                >
                  <ListItemIcon sx={{ minWidth: 26, cursor: 'grab', color: 'text.secondary' }}>
                    <DragIndicatorIcon fontSize="small" />
                  </ListItemIcon>
                  <ListItemText
                    primary={
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Chip
                          label={l.code}
                          size="small"
                          sx={{
                            height: 20,
                            fontSize: '0.7rem',
                            fontWeight: 700,
                            fontFamily: 'monospace',
                            bgcolor: 'action.hover',
                            borderRadius: 0.75
                          }}
                        />
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {l.name}
                        </Typography>
                      </Box>
                    }
                    secondary={
                      <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 1, mt: 0.25 }}>
                        <span>PIN: <strong>{l.pin || '—'}</strong></span>
                        <span>•</span>
                        <span>{l.status === 'active' ? 'Active' : 'Disabled'}</span>
                      </Box>
                    }
                    secondaryTypographyProps={{ variant: 'caption', color: 'text.secondary' }}
                    sx={{ my: 0, pr: 8 }}
                  />
                </ListItem>
              ))}
            </List>
          </CardContent>
        </Card>
      </Grid>
    </Grid>
  );

  const renderRevisionSettings = () => (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
      {/* Add New Revision Card */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 3 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 0.5 }}>
            General Revision Schedules
          </Typography>
          <Alert severity="info" variant="outlined" sx={{ mb: 2.5, borderRadius: 1.5, py: 0.5 }}>
            Leave "To Year" blank for ongoing/current revisions. Previous active revisions will automatically adjust their ending year when a new ongoing schedule is added.
          </Alert>

          <Grid container spacing={2} alignItems="flex-end">
            <Grid item xs={12} sm={4}>
              <TextField
                fullWidth
                size="small"
                label="Revision Label / Year"
                required
                value={newRevisionEntry.revision_year}
                onChange={(e) => setNewRevisionEntry({ ...newRevisionEntry, revision_year: e.target.value })}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addRevisionEntry(); } }}
                placeholder="e.g., 2024 Revision"
              />
            </Grid>
            <Grid item xs={12} sm={3}>
              <TextField
                fullWidth
                size="small"
                label="Effective From Year"
                type="number"
                required
                value={newRevisionEntry.from_year}
                onChange={(e) => setNewRevisionEntry({ ...newRevisionEntry, from_year: e.target.value })}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addRevisionEntry(); } }}
                placeholder="2024"
                inputProps={{ min: 1900, max: 2100 }}
              />
            </Grid>
            <Grid item xs={12} sm={3}>
              <TextField
                fullWidth
                size="small"
                label="Effective To Year (Optional)"
                value={newRevisionEntry.to_year}
                onChange={(e) => setNewRevisionEntry({ ...newRevisionEntry, to_year: e.target.value })}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addRevisionEntry(); } }}
                placeholder="Leave blank for ongoing"
              />
            </Grid>
            <Grid item xs={12} sm={2}>
              <Button
                fullWidth
                variant="contained"
                onClick={addRevisionEntry}
                sx={{ textTransform: 'none', height: 40 }}
              >
                + Add Revision
              </Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Revision Schedules Table */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 0 }}>
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: 'action.hover' }}>
                  <TableCell sx={{ width: 48 }} />
                  <TableCell sx={{ fontWeight: 600 }}>Revision</TableCell>
                  <TableCell sx={{ fontWeight: 600 }}>Effectivity Period</TableCell>
                  <TableCell sx={{ fontWeight: 600, width: 140 }}>Status</TableCell>
                  <TableCell sx={{ fontWeight: 600, width: 90 }} align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {(!revisionEntries || revisionEntries.length === 0) ? (
                  <TableRow>
                    <TableCell colSpan={5} align="center" sx={{ py: 4, color: 'text.secondary' }}>
                      No general revision entries configured yet.
                    </TableCell>
                  </TableRow>
                ) : (
                  revisionEntries.map((entry, index) => {
                    const isPresent = entry.to_year === 'present' || !entry.to_year;
                    return (
                      <TableRow
                        key={entry.id}
                        hover
                        draggable
                        onDragStart={() => handleDragStart('revisionEntries', index)}
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={() => handleDrop('revisionEntries', index)}
                      >
                        <TableCell sx={{ cursor: 'grab', color: 'text.secondary', width: 48 }} title="Drag to reorder">
                          <DragIndicatorIcon fontSize="small" />
                        </TableCell>
                        <TableCell sx={{ fontWeight: 600 }}>
                          {entry.revision_year}
                        </TableCell>
                        <TableCell>
                          <Box sx={{ display: 'inline-flex', alignItems: 'center', gap: 1 }}>
                            <Typography variant="body2" sx={{ fontWeight: 600 }}>
                              {entry.from_year} → {isPresent ? 'Present' : entry.to_year}
                            </Typography>
                            {isPresent && (
                              <Chip size="small" color="primary" variant="outlined" label="Current" sx={{ height: 20, fontSize: '0.7rem' }} />
                            )}
                          </Box>
                        </TableCell>
                        <TableCell>
                          <FormControlLabel
                            sx={{ m: 0 }}
                            control={
                              <Switch
                                size="small"
                                checked={entry.status === 'active'}
                                onChange={async (e) => {
                                  try {
                                    const updated = await apiService.saveRevisionEntry({
                                      id: entry.id,
                                      revision_year: entry.revision_year,
                                      from_year: entry.from_year,
                                      to_year: entry.to_year,
                                      status: e.target.checked ? 'active' : 'disabled',
                                      sort_order: entry.sort_order || 0
                                    });
                                    setRevisionEntries(updated?.items || []);
                                    setToast({ open: true, message: 'Revision entry updated.', severity: 'success' });
                                  } catch (err) {
                                    setToast({ open: true, message: 'Failed to update revision entry.', severity: 'error' });
                                  }
                                }}
                              />
                            }
                            label={entry.status === 'active' ? 'Active' : 'Disabled'}
                          />
                        </TableCell>
                        <TableCell align="right">
                          <IconButton
                            size="small"
                            aria-label="delete"
                            onClick={async () => {
                              try {
                                await apiService.deleteRevisionEntry(entry.id);
                                const res = await apiService.getRevisionEntries();
                                setRevisionEntries(res?.items || []);
                                setToast({ open: true, message: 'Revision entry deleted.', severity: 'success' });
                              } catch (err) {
                                setToast({ open: true, message: 'Failed to delete revision entry.', severity: 'error' });
                              }
                            }}
                            sx={{ color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                          >
                            <DeleteIcon fontSize="small" />
                          </IconButton>
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </CardContent>
      </Card>
    </Box>
  );

  const renderRequestPaymentInfos = () => (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
      {/* Default Place Issued Card */}
      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent sx={{ p: 3 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 0.5 }}>
            Default Issuance Place
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Specifies the default municipality location printed on issued request receipts and formal certifications.
          </Typography>
          <Grid container spacing={2}>
            <Grid item xs={12} sm={6}>
              <TextField
                fullWidth
                size="small"
                label="Default Place Issued"
                value={form.request_place_issued_default || ''}
                onChange={(e) => handleChange('request_place_issued_default', e.target.value)}
                placeholder="e.g. KITAOTAO, BUKIDNON"
                helperText="Auto-populated in Request Form certificates"
              />
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {/* Purposes & Fee Schedules (2-Column: Add on left, Table on right) */}
      <Grid container spacing={3}>
        {/* Left Column: Add New Purpose Form */}
        <Grid item xs={12} md={4} lg={3.5}>
          <Card variant="outlined" sx={{ borderRadius: 2, height: '100%' }}>
            <CardContent sx={{ p: 2.5 }}>
              <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 0.5 }}>
                Add Request Purpose
              </Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                Configure standard certification purposes and official fees.
              </Typography>

              <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                <TextField
                  fullWidth
                  size="small"
                  label="Purpose Description"
                  placeholder="CERTIFICATION_FEE"
                  value={newRequestPurpose.purpose}
                  onChange={(e) => setNewRequestPurpose(prev => ({ ...prev, purpose: e.target.value.replace(/\s+/g, '_') }))}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addRequestPurpose(); } }}
                  helperText="Spaces converted to underscores"
                />
                <TextField
                  fullWidth
                  size="small"
                  label="Amount (PHP)"
                  type="number"
                  placeholder="50.00"
                  value={newRequestPurpose.amount}
                  onChange={(e) => setNewRequestPurpose(prev => ({ ...prev, amount: e.target.value }))}
                  inputProps={{ min: 0, step: 0.01 }}
                  onBlur={() => {
                    const n = Number(newRequestPurpose.amount);
                    if (!isNaN(n) && n >= 0) {
                      setNewRequestPurpose(prev => ({ ...prev, amount: n.toFixed(2) }));
                    }
                  }}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addRequestPurpose(); } }}
                />
                <Button
                  fullWidth
                  variant="contained"
                  onClick={addRequestPurpose}
                  sx={{ textTransform: 'none', py: 1 }}
                >
                  + Add Purpose & Fee
                </Button>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        {/* Right Column: Purpose Fee Table */}
        <Grid item xs={12} md={8} lg={8.5}>
          <Card variant="outlined" sx={{ borderRadius: 2 }}>
            <CardContent sx={{ p: 0 }}>
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow sx={{ bgcolor: 'action.hover' }}>
                      <TableCell sx={{ width: 44 }} />
                      <TableCell sx={{ fontWeight: 600 }}>Purpose</TableCell>
                      <TableCell sx={{ fontWeight: 600, width: 140 }} align="right">Amount</TableCell>
                      <TableCell sx={{ fontWeight: 600, width: 130 }}>Status</TableCell>
                      <TableCell sx={{ fontWeight: 600, width: 90 }} align="right">Actions</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {(!requestPurposes || requestPurposes.length === 0) ? (
                      <TableRow>
                        <TableCell colSpan={5} align="center" sx={{ py: 4, color: 'text.secondary' }}>
                          No request purposes configured yet.
                        </TableCell>
                      </TableRow>
                    ) : (
                      requestPurposes.map((p, index) => {
                        const isEditing = editingRequestPurposeId === p.id;
                        return (
                          <TableRow
                            key={p.id}
                            hover
                            draggable={!isEditing}
                            onDragStart={() => handleDragStart('requestPurposes', index)}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={() => handleDrop('requestPurposes', index)}
                            sx={{ '& td': { verticalAlign: 'middle' } }}
                          >
                            <TableCell sx={{ cursor: isEditing ? 'default' : 'grab', color: 'text.secondary', width: 44 }} title={isEditing ? '' : 'Drag to reorder'}>
                              <DragIndicatorIcon fontSize="small" />
                            </TableCell>
                            <TableCell sx={{ fontWeight: 600, wordBreak: 'break-word' }}>
                              {isEditing ? (
                                <TextField
                                  size="small"
                                  value={editRequestPurposeDraft.purpose}
                                  onChange={(e) => setEditRequestPurposeDraft(prev => ({ ...prev, purpose: e.target.value.replace(/\s+/g, '_') }))}
                                  autoFocus
                                  onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                      e.preventDefault();
                                      saveEditRequestPurpose(p);
                                    } else if (e.key === 'Escape') {
                                      e.preventDefault();
                                      cancelEditRequestPurpose();
                                    }
                                  }}
                                  fullWidth
                                />
                              ) : (
                                p.purpose
                              )}
                            </TableCell>
                            <TableCell align="right">
                              {isEditing ? (
                                <TextField
                                  size="small"
                                  type="number"
                                  value={editRequestPurposeDraft.amount}
                                  onChange={(e) => setEditRequestPurposeDraft(prev => ({ ...prev, amount: e.target.value }))}
                                  inputProps={{ min: 0, step: 0.01 }}
                                  onBlur={() => {
                                    const n = Number(editRequestPurposeDraft.amount);
                                    if (!isNaN(n) && n >= 0) {
                                      setEditRequestPurposeDraft(prev => ({ ...prev, amount: n.toFixed(2) }));
                                    }
                                  }}
                                  onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                      e.preventDefault();
                                      saveEditRequestPurpose(p);
                                    } else if (e.key === 'Escape') {
                                      e.preventDefault();
                                      cancelEditRequestPurpose();
                                    }
                                  }}
                                  sx={{ width: 120 }}
                                />
                              ) : (
                                <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>
                                  ₱{(() => {
                                    const n = Number(p.amount);
                                    return isNaN(n) ? String(p.amount ?? '') : n.toFixed(2);
                                  })()}
                                </Typography>
                              )}
                            </TableCell>
                            <TableCell>
                              <FormControlLabel
                                sx={{ m: 0 }}
                                control={
                                  <Switch
                                    size="small"
                                    checked={p.status === 'active'}
                                    disabled={isEditing}
                                    onChange={async (e) => {
                                      try {
                                        const res = await apiService.saveRequestPurpose({
                                          id: p.id,
                                          purpose: p.purpose,
                                          amount: Number(p.amount) || 0,
                                          status: e.target.checked ? 'active' : 'disabled',
                                          sort_order: p.sort_order || 0
                                        });
                                        setRequestPurposes(res?.items || []);
                                        setToast({ open: true, message: 'Request purpose updated.', severity: 'success' });
                                      } catch (err) {
                                        setToast({ open: true, message: err?.message || 'Failed to update request purpose.', severity: 'error' });
                                      }
                                    }}
                                  />
                                }
                                label={p.status === 'active' ? 'Active' : 'Disabled'}
                              />
                            </TableCell>
                            <TableCell align="right">
                              {!isEditing ? (
                                <Box sx={{ display: 'inline-flex', alignItems: 'center' }}>
                                  <IconButton size="small" aria-label="edit" onClick={() => beginEditRequestPurpose(p)}>
                                    <EditIcon fontSize="small" />
                                  </IconButton>
                                  <IconButton
                                    size="small"
                                    aria-label="delete"
                                    onClick={() => confirmDeleteRequestPurpose(p)}
                                    sx={{ color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                                  >
                                    <DeleteIcon fontSize="small" />
                                  </IconButton>
                                </Box>
                              ) : (
                                <Box sx={{ display: 'inline-flex', alignItems: 'center' }}>
                                  <IconButton size="small" color="primary" aria-label="save" onClick={() => saveEditRequestPurpose(p)}>
                                    <CheckIcon fontSize="small" />
                                  </IconButton>
                                  <IconButton size="small" aria-label="cancel" onClick={cancelEditRequestPurpose}>
                                    <CloseIcon fontSize="small" />
                                  </IconButton>
                                </Box>
                              )}
                            </TableCell>
                          </TableRow>
                        );
                      })
                    )}
                  </TableBody>
                </Table>
              </TableContainer>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Box>
  );

  const settingsNavItems = [
    { label: 'General Settings', tab: 0, description: 'Branding, office info & signatories', icon: <TuneIcon fontSize="small" /> },
    { label: 'Data Management', tab: 1, description: 'Property types, classes & barangays', icon: <StorageIcon fontSize="small" /> },
    { label: 'Revision Settings', tab: 2, description: 'General assessment revisions', icon: <EventRepeatIcon fontSize="small" /> },
    { label: 'Request Payment Info', tab: 3, description: 'Purposes, standard fees & issuance', icon: <ReceiptLongIcon fontSize="small" /> },
    { label: 'API Keys', tab: 4, description: 'External integration credentials', icon: <VpnKeyIcon fontSize="small" /> },
    ...(canManage ? [
      { label: 'Remote Sync', tab: 5, description: 'Cloud synchronization configuration', icon: <CloudSyncIcon fontSize="small" /> },
      { label: 'ETRACS Data Sync', tab: 6, description: 'ETRACS municipal database pull', icon: <SyncAltIcon fontSize="small" /> },
      { label: 'ETRACS Bldg Revisions', tab: 7, description: 'Building revision schedule mappings', icon: <DomainIcon fontSize="small" /> }
    ] : [])
  ];

  return (
    <Box
      component={motion.div}
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      sx={{
        display: 'flex',
        flexDirection: 'column',
        height: '100%',
        minHeight: 'calc(100vh - 90px)',
        pb: isFormDirty ? 9 : 3
      }}
    >
      {/* Dialogs */}
      <Dialog
        open={deleteApiKeyDialog.open}
        onClose={closeDeleteApiKeyDialog}
        TransitionProps={{
          onExited: () => setDeleteApiKeyDialog({ open: false, row: null }),
        }}
      >
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <WarningAmberIcon color="warning" />
          Delete API key
        </DialogTitle>
        <DialogContent>
          <Typography variant="body2" sx={{ mb: 2 }}>
            Delete <strong>{deleteApiKeyDialog.row?.name || 'this API key'}</strong>? External integrations using it will stop working.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={closeDeleteApiKeyDialog} variant="outlined" disabled={publicApiBusy}>
            Cancel
          </Button>
          <Button onClick={doDeleteApiKey} variant="contained" color="error" disabled={publicApiBusy} autoFocus>
            Delete
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={revealSecretDialog.open}
        onClose={closeRevealSecretDialog}
        maxWidth="sm"
        fullWidth
        disableAutoFocus
        TransitionProps={{
          onEntered: () => {
            revealPasswordInputRef.current?.focus();
          },
        }}
      >
        <DialogTitle>Confirm your password</DialogTitle>
        <DialogContent>
          {revealSecretDialog.keyName && (
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              Reveal API secret for: <strong>{revealSecretDialog.keyName}</strong>
            </Typography>
          )}
          <Typography variant="body2" sx={{ mb: 2 }}>
            Enter your account password. The secret will appear in the table.
          </Typography>
          {revealSecretDialog.error && (
            <Alert severity="error" sx={{ mb: 2 }}>
              {revealSecretDialog.error}
            </Alert>
          )}
          <TextField
            fullWidth
            type="password"
            label="Your password"
            inputRef={revealPasswordInputRef}
            value={revealSecretDialog.password}
            disabled={revealSecretDialog.loading}
            onChange={(e) => setRevealSecretDialog((prev) => ({
              ...prev,
              password: e.target.value,
              error: '',
            }))}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                submitRevealSecret();
              }
            }}
            autoFocus
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={closeRevealSecretDialog} disabled={revealSecretDialog.loading}>
            Cancel
          </Button>
          <Button
            variant="contained"
            disabled={revealSecretDialog.loading}
            onClick={submitRevealSecret}
          >
            {revealSecretDialog.loading ? 'Verifying…' : 'Confirm'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        key={newKeyDialog.dialogKey || 'new-api-key-closed'}
        open={newKeyDialog.open}
        onClose={() => { }}
        disableEscapeKeyDown
      >
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <WarningAmberIcon color="warning" />
          API credentials created
        </DialogTitle>
        <DialogContent>
          <Box sx={{ mb: 2, p: 1.25, borderRadius: 1, bgcolor: 'warning.50' }}>
            <Typography variant="body2" sx={{ fontWeight: 600, color: 'warning.dark' }}>
              Copy the API key and API secret now. The secret will not be shown again.
            </Typography>
          </Box>
          {newKeyDialog.name && (
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
              Key name: <strong>{newKeyDialog.name}</strong>
            </Typography>
          )}
          {newKeyDialog.secretMissing && !newKeyDialogCreds.apiSecret && (
            <Alert severity="warning" sx={{ mb: 2 }}>
              The API secret was blocked from the response (common on production hosting). Use the eye icon in the table with your account password to reveal it, or ask your host to allow API key create responses for{' '}
              <Box component="code" sx={{ fontSize: '0.8rem' }}>/wp-json/assessor/v1/settings/public-api-keys</Box>.
            </Alert>
          )}
          <Typography variant="caption" color="text.secondary" display="block" sx={{ mb: 0.5 }}>
            API key
          </Typography>
          <Paper variant="outlined" sx={{ p: 1.5, display: 'flex', alignItems: 'flex-start', gap: 1, mb: 2 }}>
            <Box component="code" sx={{ flex: 1, wordBreak: 'break-all', fontSize: '0.85rem' }}>
              {newKeyDialogCreds.apiKey || '—'}
            </Box>
            <IconButton
              size="small"
              aria-label="Copy API key"
              disabled={!newKeyDialogCreds.apiKey}
              onClick={() => copyToClipboard(newKeyDialogCreds.apiKey)}
            >
              <ContentCopyIcon fontSize="small" />
            </IconButton>
          </Paper>
          <Typography variant="caption" color="text.secondary" display="block" sx={{ mb: 0.5 }}>
            API secret (password)
          </Typography>
          <Paper variant="outlined" sx={{ p: 1.5, display: 'flex', alignItems: 'flex-start', gap: 1 }}>
            <Box component="code" sx={{ flex: 1, wordBreak: 'break-all', fontSize: '0.85rem' }}>
              {newKeyDialogCreds.apiSecret || '—'}
            </Box>
            <IconButton
              size="small"
              aria-label="Copy API secret"
              disabled={!newKeyDialogCreds.apiSecret}
              onClick={() => copyToClipboard(newKeyDialogCreds.apiSecret)}
            >
              <ContentCopyIcon fontSize="small" />
            </IconButton>
          </Paper>
        </DialogContent>
        <DialogActions>
          <Button
            variant="outlined"
            startIcon={<ContentCopyIcon />}
            disabled={!newKeyDialogCreds.apiKey && !newKeyDialogCreds.apiSecret}
            onClick={() => copyToClipboard(`API Key: ${newKeyDialogCreds.apiKey}\nAPI Secret: ${newKeyDialogCreds.apiSecret}`)}
          >
            Copy both
          </Button>
          <Button variant="contained" onClick={closeNewKeyDialog} autoFocus>
            I have saved the credentials
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog
        open={deletePurposeDialog.open}
        onClose={closeDeleteRequestPurpose}
        TransitionProps={{
          onExited: () => setDeletePurposeDialog({ open: false, row: null })
        }}
      >
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <WarningAmberIcon color="warning" />
          Delete
        </DialogTitle>
        <DialogContent>
          <Box sx={{ mb: 2, p: 1.25, borderRadius: 1, bgcolor: 'warning.50' }}>
            <Typography variant="body2" sx={{ fontWeight: 600, color: 'warning.dark' }}>
              This action cannot be undone.
            </Typography>
            <Typography variant="caption" color="text.secondary">
              Tip: disable the purpose instead if you don’t want to lose it.
            </Typography>
          </Box>
          <Box sx={{ display: 'grid', gridTemplateColumns: '120px 1fr', rowGap: 1, columnGap: 2 }}>
            <Typography variant="body2" color="text.secondary">Purpose</Typography>
            <Typography variant="body2" sx={{ fontWeight: 700, wordBreak: 'break-word' }}>
              {deletePurposeDialog.row?.purpose || '—'}
            </Typography>
            <Typography variant="body2" color="text.secondary">Amount</Typography>
            <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>
              {(() => {
                const n = Number(deletePurposeDialog.row?.amount);
                return isNaN(n) ? '—' : n.toFixed(2);
              })()}
            </Typography>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={closeDeleteRequestPurpose} variant="outlined">
            Cancel
          </Button>
          <Button onClick={doDeleteRequestPurpose} variant="contained" color="error" autoFocus>
            Delete
          </Button>
        </DialogActions>
      </Dialog>

      {/* Feedback Toast */}
      <Snackbar
        open={toast.open}
        autoHideDuration={toast.severity === 'error' ? 7000 : toast.severity === 'warning' ? 6000 : 3500}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'center' }}
      >
        <Alert
          onClose={() => setToast(prev => ({ ...prev, open: false }))}
          severity={toast.severity}
          sx={{
            width: '100%',
            alignItems: 'flex-start',
            '& .MuiAlert-message': {
              width: '100%',
              overflowWrap: 'anywhere',
              maxHeight: '60vh',
              overflowY: 'auto'
            }
          }}
        >
          {toast.message}
        </Alert>
      </Snackbar>

      {/* Top Header */}
      <Box sx={{ mb: 2.5 }}>
        <Typography variant="h5" sx={{ fontWeight: 700, color: 'text.primary', mb: 0.25 }}>
          Settings
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Configure the application, office information and system behavior.
        </Typography>
      </Box>

      {/* Two-Column Shell */}
      <Box sx={{ display: 'flex', flexDirection: { xs: 'column', md: 'row' }, gap: 2.5, flexGrow: 1, alignItems: 'flex-start' }}>
        {/* Left Navigation Rail */}
        <Paper
          variant="outlined"
          sx={{
            width: { xs: '100%', md: 240 },
            flexShrink: 0,
            borderRadius: 2,
            overflow: 'hidden'
          }}
        >
          <List disablePadding sx={{ p: 0.75 }}>
            {settingsNavItems.map((item) => {
              const isSelected = activeTab === item.tab;
              return (
                <ListItem
                  button
                  key={item.tab}
                  onClick={() => setActiveTab(item.tab)}
                  sx={{
                    borderRadius: 1.5,
                    mb: 0.5,
                    px: 1.25,
                    py: 0.85,
                    bgcolor: isSelected ? 'action.selected' : 'transparent',
                    color: isSelected ? 'primary.main' : 'inherit',
                    '&:hover': {
                      bgcolor: isSelected ? 'action.selected' : 'action.hover'
                    }
                  }}
                >
                  <ListItemIcon sx={{ minWidth: 32, color: isSelected ? 'primary.main' : 'text.secondary' }}>
                    {item.icon}
                  </ListItemIcon>
                  <ListItemText
                    primary={item.label}
                    secondary={item.description}
                    primaryTypographyProps={{
                      variant: 'body2',
                      fontWeight: isSelected ? 700 : 500,
                      color: isSelected ? 'primary.main' : 'text.primary'
                    }}
                    secondaryTypographyProps={{
                      variant: 'caption',
                      sx: { display: 'block', mt: 0.25, fontSize: '0.7rem' }
                    }}
                  />
                </ListItem>
              );
            })}
          </List>
        </Paper>

        {/* Right Content Panel */}
        <Box sx={{ flexGrow: 1, width: { xs: '100%', md: 0 }, minWidth: 0 }}>
          {activeTab === 0 && renderGeneralSettings()}
          {activeTab === 1 && renderDataManagement()}
          {activeTab === 2 && renderRevisionSettings()}
          {activeTab === 3 && renderRequestPaymentInfos()}
          {activeTab === 4 && renderPublicApiKeys()}
          {activeTab === 5 && canManage && renderSyncSettings()}
          {activeTab === 6 && canManage && renderEtracsSyncSettings()}
          {activeTab === 7 && canManage && <EtracsBuildingRevisionSettings />}
        </Box>
      </Box>

      {/* Sticky Bottom Save Bar for Form Changes */}
      {isFormDirty && (
        <Paper
          elevation={6}
          sx={{
            position: 'fixed',
            bottom: 16,
            left: '50%',
            transform: 'translateX(-50%)',
            zIndex: 1200,
            px: 2.5,
            py: 1.25,
            borderRadius: 3,
            display: 'flex',
            alignItems: 'center',
            gap: 2,
            bgcolor: 'background.paper',
            border: '1px solid',
            borderColor: 'divider',
            boxShadow: '0 8px 32px rgba(0,0,0,0.15)',
            maxWidth: '90vw'
          }}
        >
          <Typography variant="body2" sx={{ fontWeight: 600, color: 'text.primary' }}>
            Unsaved changes
          </Typography>
          <Box sx={{ display: 'flex', gap: 1 }}>
            <Button
              variant="outlined"
              size="small"
              onClick={handleCancelSettings}
              sx={{ textTransform: 'none' }}
            >
              Discard
            </Button>
            <Button
              variant="contained"
              size="small"
              onClick={handleSave}
              sx={{ textTransform: 'none' }}
            >
              Save Changes
            </Button>
          </Box>
        </Paper>
      )}
    </Box>
  );
};

export default Settings;


