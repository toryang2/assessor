import React, { useEffect, useState } from 'react';
import { Box, Card, CardContent, TextField, Button, Grid, Typography, Alert, Divider, List, ListItem, ListItemText, IconButton, Switch, FormControlLabel, Paper, Snackbar, ListItemIcon } from '@mui/material';
import DragIndicatorIcon from '@mui/icons-material/DragIndicator';
import DeleteIcon from '@mui/icons-material/Delete';
import { motion } from 'framer-motion';
import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';

const DEFAULTS = {
  app_logo_url: '',
  header_photo_url: '',
  header_province: 'BUKIDNON',
  header_municipality: 'KITAOTAO',
  header_office: 'OFFICE OF THE MUNICIPAL ASSESSOR',
  verifier_signatory_name: '',
  verifier_signatory_title: '',
  municipal_assessor_name: '',
  municipal_assessor_license: '',
  municipal_assessor_title: '',
  municipal_assessor_suffix: '',
  afk_timeout: 30
};

const Settings = () => {
  const { canManage, afkTimeout, updateAfkTimeout } = useAuth();
  const [form, setForm] = useState({
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
  const [newLocation, setNewLocation] = useState({ code: '', name: '' });
  const [pendingLogoFile, setPendingLogoFile] = useState(null);
  const [pendingLogoPreview, setPendingLogoPreview] = useState('');
  const [pendingHeaderPhotoFile, setPendingHeaderPhotoFile] = useState(null);
  const [pendingHeaderPhotoPreview, setPendingHeaderPhotoPreview] = useState('');
  const [dragging, setDragging] = useState({ key: null, from: -1 });

  useEffect(() => {
    const load = async () => {
      try {
        const data = await apiService.getSettings();
        setForm({
          app_logo_url: data.app_logo_url || DEFAULTS.app_logo_url,
          header_photo_url: data.header_photo_url || DEFAULTS.header_photo_url,
          header_province: data.header_province || DEFAULTS.header_province,
          header_municipality: data.header_municipality || DEFAULTS.header_municipality,
          header_office: data.header_office || DEFAULTS.header_office,
          verifier_signatory_name: data.verifier_signatory_name || DEFAULTS.verifier_signatory_name,
          verifier_signatory_title: data.verifier_signatory_title || DEFAULTS.verifier_signatory_title,
          municipal_assessor_name: data.municipal_assessor_name || DEFAULTS.municipal_assessor_name,
          municipal_assessor_license: data.municipal_assessor_license || DEFAULTS.municipal_assessor_license,
          municipal_assessor_title: data.municipal_assessor_title || DEFAULTS.municipal_assessor_title,
          municipal_assessor_suffix: data.municipal_assessor_suffix || DEFAULTS.municipal_assessor_suffix,
          afk_timeout: data.afk_timeout ?? afkTimeout ?? DEFAULTS.afk_timeout
        });
        const [typesRes, classesRes, locationsRes] = await Promise.all([
          apiService.getPropertyTypes(),
          apiService.getGeneralClasses(),
          apiService.getLocations()
        ]);
        setPropertyTypes(typesRes?.items || []);
        setGeneralClasses(classesRes?.items || []);
        setLocations(locationsRes?.items || []);
      } catch (e) {
        // fallback to defaults silently
      }
    };
    load();
  }, []);

  // Add refresh function for cache busting
  const refreshData = async () => {
    try {
      const data = await apiService.getSettings();
      setForm({
        app_logo_url: data.app_logo_url || DEFAULTS.app_logo_url,
        header_photo_url: data.header_photo_url || DEFAULTS.header_photo_url,
        header_province: data.header_province || DEFAULTS.header_province,
        header_municipality: data.header_municipality || DEFAULTS.header_municipality,
        header_office: data.header_office || DEFAULTS.header_office,
        verifier_signatory_name: data.verifier_signatory_name || DEFAULTS.verifier_signatory_name,
        verifier_signatory_title: data.verifier_signatory_title || DEFAULTS.verifier_signatory_title,
        municipal_assessor_name: data.municipal_assessor_name || DEFAULTS.municipal_assessor_name,
        municipal_assessor_license: data.municipal_assessor_license || DEFAULTS.municipal_assessor_license,
        municipal_assessor_title: data.municipal_assessor_title || DEFAULTS.municipal_assessor_title,
        municipal_assessor_suffix: data.municipal_assessor_suffix || DEFAULTS.municipal_assessor_suffix,
        afk_timeout: data.afk_timeout ?? afkTimeout ?? DEFAULTS.afk_timeout
      });
      const [typesRes, classesRes, locationsRes] = await Promise.all([
        apiService.getPropertyTypes(),
        apiService.getGeneralClasses(),
        apiService.getLocations()
      ]);
      setPropertyTypes(typesRes?.items || []);
      setGeneralClasses(classesRes?.items || []);
      setLocations(locationsRes?.items || []);
    } catch (e) {
      // fallback to defaults silently
    }
  };

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
            try { URL.revokeObjectURL(pendingLogoPreview); } catch (e) {}
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
            try { URL.revokeObjectURL(pendingHeaderPhotoPreview); } catch (e) {}
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
        header_office: form.header_office,
        verifier_signatory_name: form.verifier_signatory_name,
        verifier_signatory_title: form.verifier_signatory_title,
        municipal_assessor_name: form.municipal_assessor_name,
        municipal_assessor_license: form.municipal_assessor_license,
        municipal_assessor_title: form.municipal_assessor_title,
        municipal_assessor_suffix: form.municipal_assessor_suffix,
        afk_timeout: form.afk_timeout ?? 30
      };
      const saved = await apiService.saveSettings(payload);
      setForm(saved);
      
      // Update the auth context with the new AFK timeout
      updateAfkTimeout(form.afk_timeout);
      
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
      try { URL.revokeObjectURL(pendingLogoPreview); } catch (e) {}
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
      try { URL.revokeObjectURL(pendingHeaderPhotoPreview); } catch (e) {}
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
      const res = await apiService.saveLocation({ code: newLocation.code, name: newLocation.name, status: 'active' });
      setLocations(res?.items || []);
      setNewLocation({ code: '', name: '' });
      setToast({ open: true, message: 'Location saved.', severity: 'success' });
    } catch (err) {
      setToast({ open: true, message: 'Failed to save location.', severity: 'error' });
    }
  };

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      {/* <Typography variant="h4" gutterBottom>
        Settings
      </Typography> */}

      <Snackbar
        open={toast.open}
        autoHideDuration={3000}
        onClose={() => setToast(prev => ({ ...prev, open: false }))}
        anchorOrigin={{ vertical: 'top', horizontal: 'right' }}
      >
        <Alert onClose={() => setToast(prev => ({ ...prev, open: false }))} severity={toast.severity} sx={{ width: '100%' }}>
          {toast.message}
        </Alert>
      </Snackbar>

      <Card>
        <CardContent>
          <Grid container spacing={2}>
            <Grid item xs={12}>
              <Grid container spacing={2} alignItems="flex-start">
                <Grid item xs={12} md={4} sx={{ display: 'flex', justifyContent: 'center' }}>
                  <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, alignItems: 'center', maxWidth: 360, width: '100%' }}>
                    <Typography variant="h6" sx={{ mb: 1 }}>Branding</Typography>
                    <Paper variant="outlined" sx={{ p: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', width: 140, height: 140, alignSelf: 'center', position: 'relative' }}>
                      {/* Current logo (fallback) */}
                      {form.app_logo_url && !pendingLogoPreview && (
                        <img src={form.app_logo_url} alt="Logo" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
                      )}
                      {/* Pending preview overlays current */}
                      {pendingLogoPreview && (
                        <img src={pendingLogoPreview} alt="New Logo Preview" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
                      )}
                      {!form.app_logo_url && !pendingLogoPreview && (
                        <Typography variant="caption" color="text.secondary">No logo uploaded</Typography>
                      )}
                    </Paper>
                    <TextField
                      fullWidth
                      size="small"
                      label="Logo URL"
                      value={form.app_logo_url}
                      onChange={(e) => handleChange('app_logo_url', e.target.value)}
                      helperText="Paste a URL or upload an image."
                    />
                    <Button fullWidth variant="outlined" component="label">
                      Upload Image
                      <input type="file" accept="image/*" hidden onChange={handleLogoUpload} />
                    </Button>
                    {pendingLogoFile && (
                      <Typography variant="caption" color="text.secondary">Staged: {pendingLogoFile.name} (will apply on Save)</Typography>
                    )}
                    <Typography variant="h6" sx={{ mb: 1 }}>Header Photo</Typography>
                    <Paper variant="outlined" sx={{ p: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', width: 280, height: 35, alignSelf: 'center', position: 'relative' }}>
                      {/* Current header photo (fallback) */}
                      {form.header_photo_url && !pendingHeaderPhotoPreview && (
                        <img src={form.header_photo_url} alt="Header Photo" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                      )}
                      {/* Pending preview overlays current */}
                      {pendingHeaderPhotoPreview && (
                        <img src={pendingHeaderPhotoPreview} alt="New Header Photo Preview" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                      )}
                      {!form.header_photo_url && !pendingHeaderPhotoPreview && (
                        <Typography variant="caption" color="text.secondary">No header photo uploaded</Typography>
                      )}
                    </Paper>
                    <TextField
                      fullWidth
                      size="small"
                      label="Header Photo URL"
                      value={form.header_photo_url}
                      onChange={(e) => handleChange('header_photo_url', e.target.value)}
                      helperText="Paste a URL or upload an image (8:1 aspect ratio recommended)."
                    />
                    <Button fullWidth variant="outlined" component="label">
                      Upload Header Photo
                      <input type="file" accept="image/*" hidden onChange={handleHeaderPhotoUpload} />
                    </Button>
                    {pendingHeaderPhotoFile && (
                      <Typography variant="caption" color="text.secondary">Staged: {pendingHeaderPhotoFile.name} (will apply on Save)</Typography>
                    )}
                  </Box>
                </Grid>
                <Grid item xs={12} md={4}>
                  <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, alignItems: 'center', width: '100%' }}>
                    <Typography variant="h6" sx={{ mb: 1 }}>Print Header Details</Typography>
                    <Grid container spacing={2}>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Province"
                          value={form.header_province.toUpperCase()}
                          onChange={(e) => handleChange('header_province', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Municipality"
                          value={form.header_municipality.toUpperCase()}
                          onChange={(e) => handleChange('header_municipality', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Office"
                          value={form.header_office.toUpperCase()}
                          onChange={(e) => handleChange('header_office', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <Typography variant="caption" color="text.secondary">
                          The first line (Republic of the Philippines) and header title are fixed in the printout.
                        </Typography>
                      </Grid>
                    </Grid>
                  </Box>
                </Grid>
                <Grid item xs={12} md={4}>
                  <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, alignItems: 'center', width: '100%' }}>
                    <Typography variant="h6" sx={{ mb: 1 }}>Signatory Details</Typography>
                    <Grid container spacing={2}>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Verifier Signatory Name"
                          value={(form.verifier_signatory_name || '').toUpperCase()}
                          onChange={(e) => handleChange('verifier_signatory_name', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Verifier Signatory Title"
                          value={(form.verifier_signatory_title || '').toUpperCase()}
                          onChange={(e) => handleChange('verifier_signatory_title', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Municipal Assessor Name"
                          value={(form.municipal_assessor_name || '').toUpperCase()}
                          onChange={(e) => handleChange('municipal_assessor_name', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Municipal Assessor Title/Suffix (e.g., MMREM, REA, REB, LPT)"
                          value={(form.municipal_assessor_suffix || '').toUpperCase()}
                          onChange={(e) => handleChange('municipal_assessor_suffix', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Title(Municipal Assessor / Acting)"
                          value={(form.municipal_assessor_title || '').toUpperCase()}
                          onChange={(e) => handleChange('municipal_assessor_title', e.target.value.toUpperCase())}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Municipal Assessor License Number"
                          value={(form.municipal_assessor_license || '').toUpperCase()}
                          onChange={(e) => handleChange('municipal_assessor_license', e.target.value.toUpperCase())}
                        />
                      </Grid>
                    </Grid>
                  </Box>
                </Grid>
              </Grid>
            </Grid>
            <Grid item xs={12}>
              <Typography variant="h6" gutterBottom>
                Security Settings
              </Typography>
              <Grid container spacing={2}>
                <Grid item xs={12} md={6}>
                  <TextField
                    fullWidth
                    label="Auto-logout timeout (minutes)"
                    type="number"
                    value={form.afk_timeout ?? 30}
                    onChange={(e) => handleAfkTimeoutChange(e.target.value)}
                    helperText="Automatically log out after this many minutes of inactivity (5-480 minutes)"
                    inputProps={{ min: 5, max: 480 }}
                    size="small"
                    error={form.afk_timeout !== '' && (form.afk_timeout < 5 || form.afk_timeout > 480)}
                  />
                </Grid>
                <Grid item xs={12} md={6}>
                  <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                    The system will automatically log you out after {form.afk_timeout ?? 30} minutes of inactivity. 
                    This helps protect your session when you step away from your computer.
                  </Typography>
                </Grid>
              </Grid>
            </Grid>
            <Grid item xs={12} textAlign="right">
              <Button variant="contained" onClick={handleSave}>Save</Button>
            </Grid>
            <Grid item xs={12}>
              <Divider sx={{ my: 2 }} />
              <Grid container spacing={2}>
                <Grid item xs={12} md={6} lg={4}>
                  <Typography variant="h6">Property Types</Typography>
                  <Grid container spacing={2} alignItems="flex-start" sx={{ mt: 1 }}>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Code" required value={newType.code}
                        onChange={(e) => setNewType({ ...newType, code: e.target.value.toUpperCase() })}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPropertyType(); } }}
                      />
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Name" required value={newType.name}
                        onChange={(e) => setNewType({ ...newType, name: e.target.value.toUpperCase() })}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPropertyType(); } }}
                      />
                    </Grid>
                    <Grid item xs={12}>
                      <Button fullWidth variant="outlined" onClick={addPropertyType}>Add</Button>
                    </Grid>
                  </Grid>
                  <List dense>
                    {(propertyTypes || []).map((t, index) => (
                      <ListItem key={t.id} draggable onDragStart={() => handleDragStart('propertyTypes', index)} onDragOver={(e) => e.preventDefault()} onDrop={() => handleDrop('propertyTypes', index)} secondaryAction={
                        <IconButton edge="end" aria-label="delete" onClick={async () => {
                          try {
                            await apiService.deletePropertyType(t.id);
                            const res = await apiService.getPropertyTypes();
                            setPropertyTypes(res?.items || []);
                            setToast({ open: true, message: 'Property type deleted.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to delete property type.', severity: 'error' });
                          }
                        }}>
                          <DeleteIcon />
                        </IconButton>
                      }>
                        <ListItemIcon sx={{ minWidth: 32, cursor: 'grab', color: 'text.secondary' }}>
                          <DragIndicatorIcon fontSize="small" />
                        </ListItemIcon>
                        <ListItemText primaryTypographyProps={{ sx: { wordBreak: 'break-word' } }} primary={t.name} />
                        <FormControlLabel sx={{ ml: 2 }} control={<Switch size="small" checked={t.status === 'active'} onChange={async (e) => {
                          try {
                            const updated = await apiService.savePropertyType({ id: t.id, code: t.code, name: t.name, status: e.target.checked ? 'active' : 'disabled', sort_order: t.sort_order || 0 });
                            setPropertyTypes(updated?.items || []);
                            setToast({ open: true, message: 'Property type updated.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to update property type.', severity: 'error' });
                          }
                        }} />} label={t.status === 'active' ? 'Active' : 'Disabled'} />
                      </ListItem>
                    ))}
                  </List>
                </Grid>

                <Grid item xs={12} md={6} lg={4}>
                  <Typography variant="h6">General Classes</Typography>
                  <Grid container spacing={2} alignItems="flex-start" sx={{ mt: 1 }}>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Code" required value={newClass.code}
                        onChange={(e) => setNewClass({ ...newClass, code: e.target.value.toUpperCase() })}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addGeneralClass(); } }}
                      />
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Name" required value={newClass.name}
                        onChange={(e) => setNewClass({ ...newClass, name: e.target.value.toUpperCase() })}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addGeneralClass(); } }}
                      />
                    </Grid>
                    <Grid item xs={12}>
                      <Button fullWidth variant="outlined" onClick={addGeneralClass}>Add</Button>
                    </Grid>
                  </Grid>
                  <List dense>
                    {(generalClasses || []).map((c, index) => (
                      <ListItem key={c.id} draggable onDragStart={() => handleDragStart('generalClasses', index)} onDragOver={(e) => e.preventDefault()} onDrop={() => handleDrop('generalClasses', index)} secondaryAction={
                        <IconButton edge="end" aria-label="delete" onClick={async () => {
                          try {
                            await apiService.deleteGeneralClass(c.id);
                            const res = await apiService.getGeneralClasses();
                            setGeneralClasses(res?.items || []);
                            setToast({ open: true, message: 'General class deleted.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to delete general class.', severity: 'error' });
                          }
                        }}>
                          <DeleteIcon />
                        </IconButton>
                      }>
                        <ListItemIcon sx={{ minWidth: 32, cursor: 'grab', color: 'text.secondary' }}>
                          <DragIndicatorIcon fontSize="small" />
                        </ListItemIcon>
                        <ListItemText primaryTypographyProps={{ sx: { wordBreak: 'break-word' } }} primary={c.name} />
                        <FormControlLabel sx={{ ml: 2 }} control={<Switch size="small" checked={c.status === 'active'} onChange={async (e) => {
                          try {
                            const updated = await apiService.saveGeneralClass({ id: c.id, code: c.code, name: c.name, status: e.target.checked ? 'active' : 'disabled', sort_order: c.sort_order || 0 });
                            setGeneralClasses(updated?.items || []);
                            setToast({ open: true, message: 'General class updated.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to update general class.', severity: 'error' });
                          }
                        }} />} label={c.status === 'active' ? 'Active' : 'Disabled'} />
                      </ListItem>
                    ))}
                  </List>
                </Grid>

                <Grid item xs={12} md={6} lg={4}>
                  <Typography variant="h6">Locations</Typography>
                  <Grid container spacing={2} alignItems="flex-start" sx={{ mt: 1 }}>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Code" required value={newLocation.code}
                        onChange={(e) => setNewLocation({ ...newLocation, code: e.target.value.toUpperCase() })}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addLocation(); } }}
                      />
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Name" required value={newLocation.name}
                        onChange={(e) => setNewLocation({ ...newLocation, name: e.target.value.toUpperCase() })}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addLocation(); } }}
                      />
                    </Grid>
                    <Grid item xs={12}>
                      <Button fullWidth variant="outlined" onClick={addLocation}>Add</Button>
                    </Grid>
                  </Grid>
                  <List dense>
                    {(locations || []).map((l, index) => (
                      <ListItem key={l.id} draggable onDragStart={() => handleDragStart('locations', index)} onDragOver={(e) => e.preventDefault()} onDrop={() => handleDrop('locations', index)} secondaryAction={
                        <IconButton edge="end" aria-label="delete" onClick={async () => {
                          try {
                            await apiService.deleteLocation(l.id);
                            const res = await apiService.getLocations();
                            setLocations(res?.items || []);
                            setToast({ open: true, message: 'Location deleted.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to delete location.', severity: 'error' });
                          }
                        }}>
                          <DeleteIcon />
                        </IconButton>
                      }>
                        <ListItemIcon sx={{ minWidth: 32, cursor: 'grab', color: 'text.secondary' }}>
                          <DragIndicatorIcon fontSize="small" />
                        </ListItemIcon>
                        <ListItemText primaryTypographyProps={{ sx: { wordBreak: 'break-word' } }} primary={l.name} />
                        <FormControlLabel sx={{ ml: 2 }} control={<Switch size="small" checked={l.status === 'active'} onChange={async (e) => {
                          try {
                            const updated = await apiService.saveLocation({ id: l.id, code: l.code, name: l.name, status: e.target.checked ? 'active' : 'disabled', sort_order: l.sort_order || 0 });
                            setLocations(updated?.items || []);
                            setToast({ open: true, message: 'Location updated.', severity: 'success' });
                          } catch (err) {
                            setToast({ open: true, message: 'Failed to update location.', severity: 'error' });
                          }
                        }} />} label={l.status === 'active' ? 'Active' : 'Disabled'} />
                      </ListItem>
                    ))}
                  </List>
                </Grid>
              </Grid>
            </Grid>
          </Grid>
        </CardContent>
      </Card>
    </Box>
  );
};

export default Settings;


