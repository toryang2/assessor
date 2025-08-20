import React, { useEffect, useState } from 'react';
import { Box, Card, CardContent, TextField, Button, Grid, Typography, Alert, Divider, List, ListItem, ListItemText, IconButton, Switch, FormControlLabel, Paper, Snackbar } from '@mui/material';
import DeleteIcon from '@mui/icons-material/Delete';
import { motion } from 'framer-motion';
import { apiService } from '../../utils/api';

const DEFAULTS = {
  app_logo_url: '',
  header_province: 'BUKIDNON',
  header_municipality: 'KITAOTAO',
  header_office: 'OFFICE OF THE MUNICIPAL ASSESSOR'
};

const Settings = () => {
  const [form, setForm] = useState(DEFAULTS);
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

  useEffect(() => {
    const load = async () => {
      try {
        const data = await apiService.getSettings();
        setForm({
          app_logo_url: data.app_logo_url || DEFAULTS.app_logo_url,
          header_province: data.header_province || DEFAULTS.header_province,
          header_municipality: data.header_municipality || DEFAULTS.header_municipality,
          header_office: data.header_office || DEFAULTS.header_office
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

  const handleChange = (field, value) => {
    setForm(prev => ({ ...prev, [field]: value }));
  };

  const handleSave = async () => {
    try {
      // Upload pending logo first (if any), but only on Save
      if (pendingLogoFile) {
        const res = await apiService.uploadLogo(pendingLogoFile);
        setForm(prev => ({ ...prev, app_logo_url: res.app_logo_url }));
        // clear pending preview
        if (pendingLogoPreview) {
          try { URL.revokeObjectURL(pendingLogoPreview); } catch (e) {}
        }
        setPendingLogoFile(null);
        setPendingLogoPreview('');
      }
      const payload = {
        header_province: form.header_province,
        header_municipality: form.header_municipality,
        header_office: form.header_office
      };
      const saved = await apiService.saveSettings(payload);
      setForm(saved);
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
                          onChange={(e) => handleChange('header_province', e.target.value)}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Municipality"
                          value={form.header_municipality.toUpperCase()}
                          onChange={(e) => handleChange('header_municipality', e.target.value)}
                        />
                      </Grid>
                      <Grid item xs={12}>
                        <TextField
                          fullWidth
                          size="small"
                          label="Office"
                          value={form.header_office.toUpperCase()}
                          onChange={(e) => handleChange('header_office', e.target.value)}
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
                      <TextField fullWidth size="small" label="Code" value={newType.code} onChange={(e) => setNewType({ ...newType, code: e.target.value.toUpperCase() })} />
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Name" value={newType.name} onChange={(e) => setNewType({ ...newType, name: e.target.value.toUpperCase() })} />
                    </Grid>
                    <Grid item xs={12}>
                      <Button fullWidth variant="outlined" onClick={async () => {
                        if (!newType.code || !newType.name) return;
                        const res = await apiService.savePropertyType({ code: newType.code, name: newType.name, status: 'active' });
                        setPropertyTypes(res?.items || []);
                        setNewType({ code: '', name: '' });
                      }}>Add</Button>
                    </Grid>
                  </Grid>
                  <List dense>
                    {(propertyTypes || []).map((t) => (
                      <ListItem key={t.id} secondaryAction={
                        <IconButton edge="end" aria-label="delete" onClick={async () => {
                          await apiService.deletePropertyType(t.id);
                          const res = await apiService.getPropertyTypes();
                          setPropertyTypes(res?.items || []);
                        }}>
                          <DeleteIcon />
                        </IconButton>
                      }>
                        <ListItemText primaryTypographyProps={{ sx: { wordBreak: 'break-word' } }} primary={`${t.code} — ${t.name}`} />
                        <FormControlLabel sx={{ ml: 2 }} control={<Switch size="small" checked={t.status === 'active'} onChange={async (e) => {
                          const updated = await apiService.savePropertyType({ id: t.id, code: t.code, name: t.name, status: e.target.checked ? 'active' : 'disabled', sort_order: t.sort_order || 0 });
                          setPropertyTypes(updated?.items || []);
                        }} />} label={t.status === 'active' ? 'Active' : 'Disabled'} />
                      </ListItem>
                    ))}
                  </List>
                </Grid>

                <Grid item xs={12} md={6} lg={4}>
                  <Typography variant="h6">General Classes</Typography>
                  <Grid container spacing={2} alignItems="flex-start" sx={{ mt: 1 }}>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Code" value={newClass.code} onChange={(e) => setNewClass({ ...newClass, code: e.target.value.toUpperCase() })} />
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Name" value={newClass.name} onChange={(e) => setNewClass({ ...newClass, name: e.target.value.toUpperCase() })} />
                    </Grid>
                    <Grid item xs={12}>
                      <Button fullWidth variant="outlined" onClick={async () => {
                        if (!newClass.code || !newClass.name) return;
                        const res = await apiService.saveGeneralClass({ code: newClass.code, name: newClass.name, status: 'active' });
                        setGeneralClasses(res?.items || []);
                        setNewClass({ code: '', name: '' });
                      }}>Add</Button>
                    </Grid>
                  </Grid>
                  <List dense>
                    {(generalClasses || []).map((c) => (
                      <ListItem key={c.id} secondaryAction={
                        <IconButton edge="end" aria-label="delete" onClick={async () => {
                          await apiService.deleteGeneralClass(c.id);
                          const res = await apiService.getGeneralClasses();
                          setGeneralClasses(res?.items || []);
                        }}>
                          <DeleteIcon />
                        </IconButton>
                      }>
                        <ListItemText primaryTypographyProps={{ sx: { wordBreak: 'break-word' } }} primary={`${c.code} — ${c.name}`} />
                        <FormControlLabel sx={{ ml: 2 }} control={<Switch size="small" checked={c.status === 'active'} onChange={async (e) => {
                          const updated = await apiService.saveGeneralClass({ id: c.id, code: c.code, name: c.name, status: e.target.checked ? 'active' : 'disabled', sort_order: c.sort_order || 0 });
                          setGeneralClasses(updated?.items || []);
                        }} />} label={c.status === 'active' ? 'Active' : 'Disabled'} />
                      </ListItem>
                    ))}
                  </List>
                </Grid>

                <Grid item xs={12} md={6} lg={4}>
                  <Typography variant="h6">Locations</Typography>
                  <Grid container spacing={2} alignItems="flex-start" sx={{ mt: 1 }}>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Code" value={newLocation.code} onChange={(e) => setNewLocation({ ...newLocation, code: e.target.value.toUpperCase() })} />
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <TextField fullWidth size="small" label="Name" value={newLocation.name} onChange={(e) => setNewLocation({ ...newLocation, name: e.target.value.toUpperCase() })} />
                    </Grid>
                    <Grid item xs={12}>
                      <Button fullWidth variant="outlined" onClick={async () => {
                        if (!newLocation.code || !newLocation.name) return;
                        const res = await apiService.saveLocation({ code: newLocation.code, name: newLocation.name, status: 'active' });
                        setLocations(res?.items || []);
                        setNewLocation({ code: '', name: '' });
                      }}>Add</Button>
                    </Grid>
                  </Grid>
                  <List dense>
                    {(locations || []).map((l) => (
                      <ListItem key={l.id} secondaryAction={
                        <IconButton edge="end" aria-label="delete" onClick={async () => {
                          await apiService.deleteLocation(l.id);
                          const res = await apiService.getLocations();
                          setLocations(res?.items || []);
                        }}>
                          <DeleteIcon />
                        </IconButton>
                      }>
                        <ListItemText primaryTypographyProps={{ sx: { wordBreak: 'break-word' } }} primary={`${l.code} — ${l.name}`} />
                        <FormControlLabel sx={{ ml: 2 }} control={<Switch size="small" checked={l.status === 'active'} onChange={async (e) => {
                          const updated = await apiService.saveLocation({ id: l.id, code: l.code, name: l.name, status: e.target.checked ? 'active' : 'disabled', sort_order: l.sort_order || 0 });
                          setLocations(updated?.items || []);
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


