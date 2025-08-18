import React, { useEffect, useState } from 'react';
import { Box, Card, CardContent, TextField, Button, Grid, Typography, Alert, Table, TableBody, TableRow, TableCell } from '@mui/material';
import { motion } from 'framer-motion';
import { apiService } from '../../utils/api';

const DEFAULTS = {
  app_logo_url: '',
  header_province: 'Province of Bukidnon',
  header_municipality: 'MUNICIPALITY OF KITAOTAO',
  header_office: 'OFFICE OF THE MUNICIPAL ASSESSOR'
};

const Settings = () => {
  const [form, setForm] = useState(DEFAULTS);
  const [saved, setSaved] = useState(false);

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
      const payload = {
        header_province: form.header_province,
        header_municipality: form.header_municipality,
        header_office: form.header_office
      };
      const saved = await apiService.saveSettings(payload);
      setForm(saved);
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    } catch (e) {
      // surface minimal error state
      setSaved(false);
    }
  };

  const handleLogoUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const res = await apiService.uploadLogo(file);
      setForm(prev => ({ ...prev, app_logo_url: res.app_logo_url }));
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } catch (err) {}
  };

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      <Typography variant="h4" gutterBottom>
        Settings
      </Typography>

      {saved && (
        <Alert severity="success" sx={{ mb: 2 }}>Saved successfully.</Alert>
      )}

      <Card>
        <CardContent>
          <Grid container spacing={2}>
            <Grid item xs={12}>
              <TextField
                fullWidth
                label="Logo URL"
                value={form.app_logo_url}
                onChange={(e) => handleChange('app_logo_url', e.target.value)}
                helperText="This logo will be used for the sidebar/login and printing header"
              />
            </Grid>
            <Grid item xs={12}>
              <Button variant="outlined" component="label">
                Upload Logo Image
                <input type="file" accept="image/*" hidden onChange={handleLogoUpload} />
              </Button>
              {form.app_logo_url && (
                <Box mt={2}>
                  <img src={form.app_logo_url} alt="Logo" style={{ maxHeight: 80 }} />
                </Box>
              )}
            </Grid>
            <Grid item xs={12}>
              <Typography variant="subtitle1" sx={{ mb: 1 }}>Print Header Details</Typography>
              <Table size="small">
                <TableBody>
                  <TableRow>
                    <TableCell sx={{ width: 180, fontWeight: 600 }}>Province</TableCell>
                    <TableCell>
                      <TextField fullWidth value={form.header_province.toUpperCase()} onChange={(e) => handleChange('header_province', e.target.value)} />
                    </TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell sx={{ width: 180, fontWeight: 600 }}>Municipality</TableCell>
                    <TableCell>
                      <TextField fullWidth value={form.header_municipality.toUpperCase()} onChange={(e) => handleChange('header_municipality', e.target.value)} />
                    </TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell sx={{ width: 180, fontWeight: 600 }}>Office</TableCell>
                    <TableCell>
                      <TextField fullWidth value={form.header_office.toUpperCase()} onChange={(e) => handleChange('header_office', e.target.value)} />
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
              <Typography variant="caption" color="text.secondary">The first line (Republic of the Philippines) and header title are fixed in the printout.</Typography>
            </Grid>
            <Grid item xs={12} textAlign="right">
              <Button variant="contained" onClick={handleSave}>Save</Button>
            </Grid>
          </Grid>
        </CardContent>
      </Card>
    </Box>
  );
};

export default Settings;


