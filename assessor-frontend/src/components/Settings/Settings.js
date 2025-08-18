import React, { useEffect, useState } from 'react';
import { Box, Card, CardContent, TextField, Button, Grid, Typography, Alert } from '@mui/material';
import { motion } from 'framer-motion';

const DEFAULTS = {
  app_logo_url: '',
  header_ph: 'Republic of the Philippines',
  header_province: 'Province of Bukidnon',
  header_municipality: 'MUNICIPALITY OF KITAOTAO',
  header_office: 'OFFICE OF THE MUNICIPAL ASSESSOR',
  header_title: 'RECORD VERIFICATION DATA FORM'
};

const Settings = () => {
  const [form, setForm] = useState(DEFAULTS);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    setForm({
      app_logo_url: localStorage.getItem('app_logo_url') || DEFAULTS.app_logo_url,
      header_ph: localStorage.getItem('header_ph') || DEFAULTS.header_ph,
      header_province: localStorage.getItem('header_province') || DEFAULTS.header_province,
      header_municipality: localStorage.getItem('header_municipality') || DEFAULTS.header_municipality,
      header_office: localStorage.getItem('header_office') || DEFAULTS.header_office,
      header_title: localStorage.getItem('header_title') || DEFAULTS.header_title
    });
  }, []);

  const handleChange = (field, value) => {
    setForm(prev => ({ ...prev, [field]: value }));
  };

  const handleSave = () => {
    Object.entries(form).forEach(([k, v]) => localStorage.setItem(k, v || ''));
    setSaved(true);
    setTimeout(() => setSaved(false), 2500);
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
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Line 1"
                value={form.header_ph}
                onChange={(e) => handleChange('header_ph', e.target.value)}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Province"
                value={form.header_province}
                onChange={(e) => handleChange('header_province', e.target.value)}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Municipality"
                value={form.header_municipality}
                onChange={(e) => handleChange('header_municipality', e.target.value)}
              />
            </Grid>
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                label="Office"
                value={form.header_office}
                onChange={(e) => handleChange('header_office', e.target.value)}
              />
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                label="Header Title"
                value={form.header_title}
                onChange={(e) => handleChange('header_title', e.target.value)}
              />
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


