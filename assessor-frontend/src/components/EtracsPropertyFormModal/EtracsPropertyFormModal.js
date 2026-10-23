import React, { useState, useEffect } from 'react';
import {
  Box, TextField, Grid, FormControl, InputLabel, Select, MenuItem, Button, Typography, Alert,
  Divider, Card, CardContent, Snackbar, Dialog, DialogTitle, DialogContent, Switch, FormControlLabel,
  Stepper, Step, StepLabel, RadioGroup, Radio, Autocomplete, Tabs, Tab, TableContainer, Table,
  TableHead, TableRow, TableCell, TableBody, Paper, CircularProgress
} from '@mui/material';
import { motion } from 'framer-motion';
import { etracsService, apiService } from '../../utils/api';
import BuildingFaasEditor from './BuildingFaasEditor';

const defaultFormData = {
  // FAAS fields
  tdno: '', utdno: '', pin_type: 'NEW', section: '', parcel: '', claim_no: '',
  txntype_code: 'GR', effectivity_year: '', effectivity_qtr: '', owner_name: '', owner_address: '',
  administrator_name: '', administrator_address: '', beneficiary_name: '', beneficiary_address: '',
  pin: '', title_type: '', title_no: '',  title_date: '', prevfaasid: '', prevtdno: '', prev_owner: '', prev_pin: '', prev_administrator: '',
  prev_assessed_value: '', prev_market_value: '', prev_area_hectare: '', prev_area_sqm: '',
  prev_effectivity: '', memoranda: '', back_tax_years: 0, ry_ordinance_no: '', ry_ordinance_date: '',
  date_approved: '', year_issued: '', public_land: 0,
  // RPU fields
  rpu_type: 'LAND', classification: '', ry: '', total_market_value: '', total_assessed_value: '', taxable: 1,
  // Real Property fields
  cadastral_lot_no: '', survey_no: '', block_no: '', barangay: '', barangayid: '', municipality: '', province: '',
  total_area_hectare: '', total_area_sqm: '', north: '', south: '', east: '', west: '',
  building: { floors: [], structures: [], uses: [] },
};

const rpuTypes = [
  { code: 'LAND', label: 'Land' },
  { code: 'BLDG', label: 'Building' },
  { code: 'MACH', label: 'Machinery' },
  { code: 'PLANTTREE', label: 'Plant/Tree' },
  { code: 'MISC', label: 'Miscellaneous' },
];

const titleTypes = ['', 'OCT', 'TCT', 'CLOA', 'EP', 'FPA', 'MSA'];

const EtracsPropertyFormModal = ({ open, onClose, property, onSave, transactionTypes = [] }) => {
  const isEditing = !!property;
  const [formData, setFormData] = useState({ ...defaultFormData });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState({ open: false, message: '', severity: 'success' });
  const [activeStep, setActiveStep] = useState(0);
  const [activeTab, setActiveTab] = useState(0);

  // Lookups
  const [settings, setSettings] = useState(null);
  const [barangays, setBarangays] = useState([]);
  const [classifications, setClassifications] = useState([]);
  const [exemptionTypes, setExemptionTypes] = useState([]);
  const [revisionEntries, setRevisionEntries] = useState([]);
  const [entityOptions, setEntityOptions] = useState([]);
  const [faasOptions, setFaasOptions] = useState([]);

  // Subtype detail data
  const [rpuData, setRpuData] = useState(null);
  const [bldgLookups, setBldgLookups] = useState(null);
  const [signatory, setSignatory] = useState({});
  const [assessments, setAssessments] = useState([]);

  useEffect(() => {
    if (open) {
      setActiveStep(isEditing ? 1 : 0);
      setActiveTab(0);
      setRpuData(null);
      setAssessments([]);
      setSignatory({});

      Promise.all([
        apiService.getSettings(),
        etracsService.getBarangays(),
        etracsService.getClassifications(),
        etracsService.getExemptionTypes(),
        apiService.getRevisionEntries()
      ]).then(([settingsRes, brgys, classes, exempts, revs]) => {
        setSettings(settingsRes || {});
        setBarangays(Array.isArray(brgys) ? brgys : []);
        setClassifications(Array.isArray(classes) ? classes : []);
        setExemptionTypes(Array.isArray(exempts) ? exempts : []);
        setRevisionEntries(revs?.items || []);
      }).catch(console.error);

      if (isEditing) {
        let parsedSection = '';
        let parsedParcel = '';
        const existingPin = property.pin || property.rp_pin || '';
        if (existingPin) {
          const parts = existingPin.split('-');
          if (parts.length >= 5) {
            parsedSection = parts[3];
            parsedParcel = parts[4];
          }
        }

        setFormData({
          ...defaultFormData,
          ...Object.fromEntries(
            Object.keys(defaultFormData).map((key) => [
              key,
              property[key] !== null && property[key] !== undefined ? property[key] : defaultFormData[key],
            ])
          ),
          barangay: property.barangay || '',
          barangayid: property.barangayid || '',
          rpu_type: property.rpu_type || 'LAND',
          classification: property.classification || '',
          ry: property.revision_year || property.ry || '',
          pin: existingPin,
          section: property.section || parsedSection || '',
          parcel: property.parcel || parsedParcel || '',
        });

        if (property.rpu_id) {
          etracsService.getRpuDetail(property.rpu_id).then(response => {
            const res = response?.data || response || {};
            setRpuData(res);
            setAssessments(res.assessments || []);
            if (property.rpu_type?.toUpperCase() === 'BLDG') {
              const structType = res.structuraltype || {};
              const filteredStructType = Object.fromEntries(
                Object.entries(structType).filter(([k, v]) => v !== null && v !== 0 && v !== '0' && v !== '')
              );
              setFormData(prev => ({
                ...prev,
                building: {
                  ...(res.subtype || {}),
                  ...filteredStructType,
                  floors: res.floors || [],
                  structures: res.structures || [],
                  uses: res.uses || []
                }
              }));
            }
          }).catch(console.error);
        }
        if (property.id) {
          etracsService.getFaasSignatory(property.id).then(res => setSignatory(res)).catch(console.error);
        }
      } else {
        setFormData({ ...defaultFormData });
        setRpuData({});
        setAssessments([]);
      }
      setError(null);
    }
  }, [open, isEditing, property]);

  const fetchEntities = async (query) => {
    try {
      const res = await etracsService.getEntities({ q: query, per_page: 50 });
      setEntityOptions(res.data || []);
    } catch (e) { console.warn(e); }
  };

  const fetchFaas = async (query) => {
    if (!query || query.length < 3) return;
    try {
      const res = await etracsService.getFaasList({ q: query, per_page: 20 });
      setFaasOptions(res.data || []);
    } catch (e) { console.warn(e); }
  };

  useEffect(() => {
    if (open) {
      const timer = setTimeout(() => fetchEntities(formData.owner_name), 300);
      return () => clearTimeout(timer);
    }
  }, [formData.owner_name, open]);

  useEffect(() => {
    if (open) {
      const timer = setTimeout(() => fetchFaas(formData.prevtdno), 500);
      return () => clearTimeout(timer);
    }
  }, [formData.prevtdno, open]);

  useEffect(() => {
    if (open && formData.rpu_type?.toUpperCase() === 'BLDG') {
      const ryParams = formData.ry ? { ry: formData.ry } : {};
      etracsService.getBuildingLookups(ryParams)
        .then(res => setBldgLookups(res))
        .catch(console.error);
    }
  }, [open, formData.rpu_type, formData.ry]);

  const handlePrevFaasSelect = (event, newValue) => {
    if (newValue) {
      if (typeof newValue === 'string') {
        setFormData(prev => ({ ...prev, prevtdno: newValue, prevfaasid: '' }));
        return;
      }
      setFormData(prev => ({
        ...prev,
        prevfaasid: newValue.objid || newValue.id || '',
        prevtdno: newValue.tdno || '',
        prev_owner: newValue.owner_name || newValue.taxpayer_name || '',
        prev_assessed_value: newValue.total_assessed_value || '',
        prev_market_value: newValue.total_market_value || '',
        prev_area_hectare: newValue.total_area_hectare || '',
        prev_area_sqm: newValue.total_area_sqm || '',
      }));
    } else {
      setFormData(prev => ({ ...prev, prevtdno: '', prevfaasid: '' }));
    }
  };

  const handleChange = (field) => (e) => {
    const value = e.target.type === 'checkbox' ? (e.target.checked ? 1 : 0) : e.target.value;
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const generatePIN = () => {
    const lguBase = settings?.lgu_pin || '059-10';
    let brgyPin = '';
    const selectedBrgy = barangays.find(b => b.name === formData.barangay);
    if (selectedBrgy && selectedBrgy.indexno) {
      brgyPin = formData.pin_type === 'NEW' ? selectedBrgy.indexno.padStart(4, '0') : selectedBrgy.indexno.padStart(3, '0');
    } else {
      brgyPin = formData.pin_type === 'NEW' ? '0000' : '000';
    }
    const sec = (formData.section || '').padStart(3, '0');
    const par = (formData.parcel || '').padStart(2, '0');
    return `${lguBase}-${brgyPin}-${sec}-${par}`;
  };

  useEffect(() => {
    const newPin = generatePIN();
    setFormData(prev => prev.pin === newPin ? prev : { ...prev, pin: newPin });
  }, [formData.pin_type, formData.barangay, formData.section, formData.parcel, barangays]);

  const handleNext = () => {
    if (!formData.pin_type || !formData.ry || !formData.txntype_code || !formData.rpu_type || !formData.barangay || !formData.section || !formData.parcel) {
      setError('Please fill in all required fields before proceeding.');
      return;
    }
    setError(null);
    setFormData(prev => ({
      ...prev,
      municipality: settings?.header_municipality || prev.municipality,
      province: settings?.header_province || prev.province,
    }));
    setActiveStep(1);
  };

  const handleAddAssessmentLine = () => {
    const newLine = {
      objid: '',
      actualuse: '',
      classcode: 'RES',
      classname: 'Residential',
      areasqm: 0,
      areaha: 0,
      marketvalue: 0,
      assesslevel: 20,
      assessedvalue: 0,
      taxable: 1,
    };
    setAssessments(prev => [...prev, newLine]);
  };

  const handleAssessmentChange = (index, field, value) => {
    setAssessments(prev => {
      const updated = [...prev];
      const line = { ...updated[index], [field]: value };

      if (field === 'areasqm') {
        const sqm = parseFloat(value) || 0;
        line.areasqm = sqm;
        line.areaha = parseFloat((sqm / 10000).toFixed(4));
      } else if (field === 'areaha') {
        const ha = parseFloat(value) || 0;
        line.areaha = ha;
        line.areasqm = parseFloat((ha * 10000).toFixed(2));
      }

      if (field === 'marketvalue' || field === 'assesslevel') {
        const mv = field === 'marketvalue' ? (parseFloat(value) || 0) : (parseFloat(line.marketvalue) || 0);
        const lvl = field === 'assesslevel' ? (parseFloat(value) || 0) : (parseFloat(line.assesslevel) || 0);
        line.assessedvalue = Math.round(mv * (lvl / 100));
      }

      updated[index] = line;

      // Automatically recalculate aggregate RPU values
      const totalMv = updated.reduce((s, it) => s + (parseFloat(it.marketvalue) || 0), 0);
      const totalAv = updated.reduce((s, it) => s + (parseFloat(it.assessedvalue) || 0), 0);
      const totalSqm = updated.reduce((s, it) => s + (parseFloat(it.areasqm) || 0), 0);
      const totalHa = updated.reduce((s, it) => s + (parseFloat(it.areaha) || 0), 0);

      setFormData(f => ({
        ...f,
        total_market_value: totalMv,
        total_assessed_value: totalAv,
        total_area_sqm: totalSqm,
        total_area_hectare: totalHa,
      }));

      return updated;
    });
  };

  const handleRemoveAssessmentLine = (index) => {
    setAssessments(prev => {
      const updated = prev.filter((_, idx) => idx !== index);
      const totalMv = updated.reduce((s, it) => s + (parseFloat(it.marketvalue) || 0), 0);
      const totalAv = updated.reduce((s, it) => s + (parseFloat(it.assessedvalue) || 0), 0);
      const totalSqm = updated.reduce((s, it) => s + (parseFloat(it.areasqm) || 0), 0);
      const totalHa = updated.reduce((s, it) => s + (parseFloat(it.areaha) || 0), 0);

      setFormData(f => ({
        ...f,
        total_market_value: totalMv,
        total_assessed_value: totalAv,
        total_area_sqm: totalSqm,
        total_area_hectare: totalHa,
      }));

      return updated;
    });
  };

  const handleSubmit = async () => {
    if (!formData.tdno || !formData.owner_name) {
      setError('TD Number and Owner Name are required');
      return;
    }

    // Guard: Prevent saving or editing REPLICA records
    if (isEditing && property?.origin_type === 'REPLICA') {
      setError('REPLICA records cannot be modified via local authoring draft editor.');
      return;
    }

    try {
      setLoading(true);
      setError(null);

      const payload = {
        ...formData,
        assessments: assessments.map(a => ({
          objid: a.objid || undefined,
          actualuse: a.actualuse,
          actualuse_objid: a.actualuse_objid,
          classcode: a.classcode,
          classname: a.classname,
          classification_objid: a.classification_objid,
          rputype: a.rputype || formData.rpu_type,
          areasqm: parseFloat(a.areasqm) || 0,
          areaha: parseFloat(a.areaha) || 0,
          marketvalue: parseFloat(a.marketvalue) || 0,
          assesslevel: parseFloat(a.assesslevel) || 0,
          assessedvalue: parseFloat(a.assessedvalue) || 0,
          taxable: a.taxable !== undefined ? parseInt(a.taxable, 10) : 1,
        })),
      };

      if (isEditing) {
        await etracsService.updateFaas(property.id, payload);
        if (Object.keys(signatory).length > 0) {
          await etracsService.updateFaasSignatory(property.id, signatory);
        }
        setToast({ open: true, message: 'Draft updated successfully', severity: 'success' });
      } else {
        const res = await etracsService.createFaas(payload);
        if (res && res.id && Object.keys(signatory).length > 0) {
          await etracsService.updateFaasSignatory(res.id, signatory);
        }
        setToast({ open: true, message: 'Draft Created (Interim)', severity: 'success' });
      }
      onSave?.();
    } catch (err) {
      // Retain unsaved form data and show meaningful backend validation error
      setError(err.message || 'Failed to save declaration record');
    } finally {
      setLoading(false);
    }
  };

  const SectionHeader = ({ title }) => (
    <Typography variant="subtitle1" fontWeight={700} sx={{ mt: 2, mb: 1, color: 'primary.main' }}>
      {title}
    </Typography>
  );

  return (
    <>
      <Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth scroll="paper">
        <DialogTitle sx={{ fontWeight: 700, borderBottom: '1px solid #eee' }}>
          {isEditing ? `FAAS Record: ${formData.tdno}` : 'New FAAS Record'}
        </DialogTitle>
        <DialogContent sx={{ pt: 2, pb: 4, bgcolor: '#fbfbfb' }}>
          <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
            {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

            {!isEditing && (
              <Stepper activeStep={activeStep} sx={{ mb: 4, mt: 2 }}>
                <Step><StepLabel>Initial Information</StepLabel></Step>
                <Step><StepLabel>Full Property Data</StepLabel></Step>
              </Stepper>
            )}

            {activeStep === 0 && (
              <Card variant="outlined"><CardContent>
                <Grid container spacing={3}>
                  <Grid item xs={12} sm={6}>
                    <FormControl component="fieldset">
                      <Typography variant="caption" color="textSecondary">PIN Type</Typography>
                      <RadioGroup row value={formData.pin_type} onChange={handleChange('pin_type')}>
                        <FormControlLabel value="NEW" control={<Radio size="small"/>} label="NEW" />
                        <FormControlLabel value="OLD" control={<Radio size="small"/>} label="OLD" />
                      </RadioGroup>
                    </FormControl>
                  </Grid>
                  <Grid item xs={12} sm={6}>
                    <FormControl fullWidth size="small">
                      <InputLabel>Revision Year</InputLabel>
                      <Select value={formData.ry} onChange={handleChange('ry')} label="Revision Year">
                        {revisionEntries.map(ry => (
                          <MenuItem key={ry.id} value={ry.from_year}>{ry.from_year}</MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                  </Grid>
                  <Grid item xs={12} sm={6}>
                    <FormControl fullWidth size="small">
                      <InputLabel>Txn Type</InputLabel>
                      <Select value={formData.txntype_code} onChange={handleChange('txntype_code')} label="Txn Type">
                        {transactionTypes.map(t => (
                          <MenuItem key={t.id} value={t.code}>{t.name}</MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                  </Grid>
                  <Grid item xs={12} sm={6}>
                    <FormControl fullWidth size="small">
                      <InputLabel>Property Type</InputLabel>
                      <Select value={formData.rpu_type} onChange={handleChange('rpu_type')} label="Property Type">
                        {rpuTypes.map(pt => (
                          <MenuItem key={pt.code} value={pt.code}>{pt.label}</MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                  </Grid>
                  <Grid item xs={12} sm={6}>
                    <Autocomplete
                      size="small"
                      options={barangays}
                      getOptionLabel={(opt) => typeof opt === 'string' ? opt : opt.name}
                      value={barangays.find(b => b.name === formData.barangay) || null}
                      onChange={(e, val) => {
                        setFormData(prev => ({
                          ...prev,
                          barangay: val ? val.name : '',
                          barangayid: val ? val.objid : ''
                        }));
                      }}
                      renderInput={(params) => <TextField {...params} label="Barangay" />}
                    />
                  </Grid>
                  <Grid item xs={12} sm={3}>
                    <TextField fullWidth size="small" label="Section" value={formData.section} onChange={handleChange('section')} inputProps={{ maxLength: 3 }} />
                  </Grid>
                  <Grid item xs={12} sm={3}>
                    <TextField fullWidth size="small" label="Parcel" value={formData.parcel} onChange={handleChange('parcel')} inputProps={{ maxLength: 2 }} />
                  </Grid>
                </Grid>
                <Box sx={{ mt: 4, p: 3, bgcolor: 'background.default', borderRadius: 2, textAlign: 'center', border: '1px dashed #ccc' }}>
                  <Typography variant="overline" color="textSecondary">Live PIN Preview</Typography>
                  <Typography variant="h4" sx={{ fontWeight: 'bold', mt: 1, color: 'primary.main', letterSpacing: 2 }}>
                    {generatePIN()}
                  </Typography>
                </Box>
              </CardContent></Card>
            )}

            {activeStep === 1 && (
              <Box>
                <Card variant="outlined" sx={{ mb: 2 }}>
                  <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                    <Grid container spacing={2}>
                      <Grid item xs={12} sm={3}>
                        <TextField fullWidth size="small" label="ARP/TD No." value={formData.tdno} onChange={handleChange('tdno')} disabled={isEditing} />
                      </Grid>
                      <Grid item xs={12} sm={6}>
                        <TextField fullWidth size="small" label="PIN" value={formData.pin} disabled />
                      </Grid>
                      <Grid item xs={12} sm={3}>
                        <TextField fullWidth size="small" label="Title No." value={formData.title_no} onChange={handleChange('title_no')} />
                      </Grid>

                      <Grid item xs={12} sm={4}>
                        <Autocomplete
                          freeSolo
                          options={entityOptions}
                          getOptionLabel={(opt) => typeof opt === 'string' ? opt : opt.name || ''}
                          inputValue={formData.owner_name || ''}
                          onInputChange={(e, val) => setFormData(prev => ({ ...prev, owner_name: val }))}
                          onChange={(e, val) => {
                            if (val && typeof val !== 'string') {
                              setFormData(prev => ({ ...prev, owner_name: val.name, owner_address: val.address_text }));
                            }
                          }}
                          renderInput={(params) => <TextField {...params} fullWidth size="small" label="Owner" />}
                        />
                      </Grid>
                      <Grid item xs={12} sm={8}>
                        <TextField fullWidth size="small" label="Owner Address" value={formData.owner_address} onChange={handleChange('owner_address')} />
                      </Grid>
                    </Grid>
                  </CardContent>
                </Card>

                <Card variant="outlined" sx={{ mb: 2 }}>
                  <Box sx={{ borderBottom: 1, borderColor: 'divider', bgcolor: '#fff' }}>
                    <Tabs value={activeTab} onChange={(e, val) => setActiveTab(val)} variant="scrollable" scrollButtons="auto">
                      {formData.rpu_type?.toUpperCase() === 'BLDG' ? [
                        <Tab key="gen" label="General Information" />,
                        <Tab key="app" label="Property Appraisal" />,
                        <Tab key="str" label="Structural Materials" />,
                        <Tab key="lnd" label="Lands" />,
                        <Tab key="ass" label="Assessment" />,
                        <Tab key="sig" label="Signatories" />,
                        <Tab key="prev" label="Previous FAAS" />,
                        <Tab key="mem" label="Memoranda" />,
                        <Tab key="res" label="Restrictions" />
                      ] : [
                        <Tab key="det" label={`${formData.rpu_type} Detail`} />,
                        <Tab key="ass" label="Assessment" />,
                        <Tab key="sig" label="Signatories" />,
                        <Tab key="prev" label="Previous" />,
                        <Tab key="mem" label="Memoranda" />
                      ]}
                    </Tabs>
                  </Box>

                  <CardContent sx={{ minHeight: 300, bgcolor: '#fff' }}>
                    
                    {/* RPU Detail Tab (Non-BLDG) */}
                    {formData.rpu_type?.toUpperCase() !== 'BLDG' && activeTab === 0 && (
                      <Box>
                        {rpuData === null ? (
                          <Box textAlign="center" py={5}><CircularProgress size={30} /><Typography mt={2}>Loading RPU Details...</Typography></Box>
                        ) : (
                          <Box>
                            {/* LAND RPU Detail */}
                            {formData.rpu_type?.toUpperCase() === 'LAND' && rpuData?.landdetail && (
                              <TableContainer component={Paper} variant="outlined">
                                <Table size="small" stickyHeader>
                                  <TableHead>
                                    <TableRow>
                                      <TableCell>Subclass</TableCell>
                                      <TableCell>Specific Class</TableCell>
                                      <TableCell>Area</TableCell>
                                      <TableCell>Unit Val</TableCell>
                                      <TableCell>Base MV</TableCell>
                                      <TableCell>Adjustment</TableCell>
                                      <TableCell>Market Val</TableCell>
                                    </TableRow>
                                  </TableHead>
                                  <TableBody>
                                    {rpuData.landdetail.map((row, i) => (
                                      <TableRow key={i}>
                                        <TableCell>{row.subclassname}</TableCell>
                                        <TableCell>{row.specificclassname}</TableCell>
                                        <TableCell>{row.areasqm ? `${row.areasqm} sqm` : `${row.areaha} ha`}</TableCell>
                                        <TableCell>{Number(row.unitvalue).toLocaleString()}</TableCell>
                                        <TableCell>{Number(row.basemarketvalue).toLocaleString()}</TableCell>
                                        <TableCell>{Number(row.adjustment).toLocaleString()}</TableCell>
                                        <TableCell>{Number(row.marketvalue).toLocaleString()}</TableCell>
                                      </TableRow>
                                    ))}
                                    {rpuData.landdetail.length === 0 && <TableRow><TableCell colSpan={7} align="center">No Land Details</TableCell></TableRow>}
                                  </TableBody>
                                </Table>
                              </TableContainer>
                            )}

                            {/* MACH Detail */}
                            {formData.rpu_type?.toUpperCase() === 'MACH' && rpuData?.machines && (
                              <TableContainer component={Paper} variant="outlined">
                                <Table size="small">
                                  <TableHead><TableRow><TableCell>Machine</TableCell></TableRow></TableHead>
                                  <TableBody>
                                    {rpuData.machines.map((row, i) => (
                                      <TableRow key={i}><TableCell>{row.machinename}</TableCell></TableRow>
                                    ))}
                                  </TableBody>
                                </Table>
                              </TableContainer>
                            )}
                          </Box>
                        )}
                      </Box>
                    )}

                    {/* BLDG Detail Tabs (0-3) */}
                    {formData.rpu_type?.toUpperCase() === 'BLDG' && activeTab >= 0 && activeTab <= 3 && (
                      <BuildingFaasEditor
                        building={formData.building}
                        setBuilding={(b) => setFormData(prev => ({ ...prev, building: b }))}
                        lookups={bldgLookups}
                        classifications={classifications}
                        activeTab={activeTab}
                      />
                    )}

                    {/* Assessment Tab */}
                    {((formData.rpu_type?.toUpperCase() !== 'BLDG' && activeTab === 1) || (formData.rpu_type?.toUpperCase() === 'BLDG' && activeTab === 4)) && (
                      <Box>
                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                          <Typography variant="subtitle2" fontWeight={600}>
                            RPU Assessment Schedule Lines
                          </Typography>
                          <Button size="small" variant="outlined" onClick={handleAddAssessmentLine}>
                            + Add Line Item
                          </Button>
                        </Box>
                        <TableContainer component={Paper} variant="outlined">
                          <Table size="small">
                            <TableHead>
                              <TableRow sx={{ bgcolor: '#f0f0f0' }}>
                                <TableCell sx={{ width: '22%' }}>Actual Use</TableCell>
                                <TableCell sx={{ width: '18%' }}>Class Code</TableCell>
                                <TableCell sx={{ width: '14%' }}>Area (sqm)</TableCell>
                                <TableCell sx={{ width: '18%' }}>Market Value (₱)</TableCell>
                                <TableCell sx={{ width: '12%' }}>Assess Level (%)</TableCell>
                                <TableCell sx={{ width: '16%' }}>Assessed Value (₱)</TableCell>
                                <TableCell sx={{ width: '5%' }} align="center"></TableCell>
                              </TableRow>
                            </TableHead>
                            <TableBody>
                              {assessments.map((r, i) => (
                                <TableRow key={r.objid || i}>
                                  <TableCell>
                                    <TextField
                                      size="small"
                                      variant="standard"
                                      fullWidth
                                      value={r.actualuse || ''}
                                      placeholder="e.g. RESIDENTIAL"
                                      onChange={(e) => handleAssessmentChange(i, 'actualuse', e.target.value)}
                                    />
                                  </TableCell>
                                  <TableCell>
                                    <TextField
                                      size="small"
                                      variant="standard"
                                      fullWidth
                                      value={r.classcode || r.classname || ''}
                                      placeholder="e.g. RES"
                                      onChange={(e) => handleAssessmentChange(i, 'classcode', e.target.value)}
                                    />
                                  </TableCell>
                                  <TableCell>
                                    <TextField
                                      size="small"
                                      variant="standard"
                                      type="number"
                                      fullWidth
                                      value={r.areasqm ?? ''}
                                      onChange={(e) => handleAssessmentChange(i, 'areasqm', e.target.value)}
                                    />
                                  </TableCell>
                                  <TableCell>
                                    <TextField
                                      size="small"
                                      variant="standard"
                                      type="number"
                                      fullWidth
                                      value={r.marketvalue ?? ''}
                                      onChange={(e) => handleAssessmentChange(i, 'marketvalue', e.target.value)}
                                    />
                                  </TableCell>
                                  <TableCell>
                                    <TextField
                                      size="small"
                                      variant="standard"
                                      type="number"
                                      fullWidth
                                      value={r.assesslevel ?? 20}
                                      onChange={(e) => handleAssessmentChange(i, 'assesslevel', e.target.value)}
                                    />
                                  </TableCell>
                                  <TableCell sx={{ fontWeight: 'bold' }}>
                                    ₱{Number(r.assessedvalue || 0).toLocaleString()}
                                  </TableCell>
                                  <TableCell align="center">
                                    <Button
                                      size="small"
                                      color="error"
                                      onClick={() => handleRemoveAssessmentLine(i)}
                                      sx={{ minWidth: 28, p: 0.5 }}
                                    >
                                      ✕
                                    </Button>
                                  </TableCell>
                                </TableRow>
                              ))}
                              {assessments.length === 0 && (
                                <TableRow>
                                  <TableCell colSpan={7} align="center" sx={{ py: 3, color: 'text.secondary' }}>
                                    No assessment schedule lines yet. Click "+ Add Line Item" above to create one.
                                  </TableCell>
                                </TableRow>
                              )}
                            </TableBody>
                          </Table>
                        </TableContainer>
                        <Grid container spacing={2} sx={{ mt: 2 }}>
                          <Grid item xs={6}>
                            <Typography variant="subtitle2">
                              Aggregate Market Value: ₱{Number(formData.total_market_value || 0).toLocaleString()}
                            </Typography>
                          </Grid>
                          <Grid item xs={6}>
                            <Typography variant="subtitle2" color="primary.main">
                              Aggregate Assessed Value: ₱{Number(formData.total_assessed_value || 0).toLocaleString()}
                            </Typography>
                          </Grid>
                        </Grid>
                      </Box>
                    )}

                    {/* Signatories Tab */}
                    {((formData.rpu_type?.toUpperCase() !== 'BLDG' && activeTab === 2) || (formData.rpu_type?.toUpperCase() === 'BLDG' && activeTab === 5)) && (
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Appraiser" value={signatory.appraiser_name || ''} onChange={(e) => setSignatory(s => ({ ...s, appraiser_name: e.target.value }))} /></Grid>
                        <Grid item xs={12} sm={6}><TextField type="date" InputLabelProps={{ shrink: true }} fullWidth size="small" variant="standard" label="Appraiser Date" value={signatory.appraiser_dtsigned || ''} onChange={(e) => setSignatory(s => ({ ...s, appraiser_dtsigned: e.target.value }))} /></Grid>
                        
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Recommender" value={signatory.recommender_name || ''} onChange={(e) => setSignatory(s => ({ ...s, recommender_name: e.target.value }))} /></Grid>
                        <Grid item xs={12} sm={6}><TextField type="date" InputLabelProps={{ shrink: true }} fullWidth size="small" variant="standard" label="Recommender Date" value={signatory.recommender_dtsigned || ''} onChange={(e) => setSignatory(s => ({ ...s, recommender_dtsigned: e.target.value }))} /></Grid>
                        
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Approver (Assessor)" value={signatory.approver_name || ''} onChange={(e) => setSignatory(s => ({ ...s, approver_name: e.target.value }))} /></Grid>
                        <Grid item xs={12} sm={6}><TextField type="date" InputLabelProps={{ shrink: true }} fullWidth size="small" variant="standard" label="Approver Date" value={signatory.approver_dtsigned || ''} onChange={(e) => setSignatory(s => ({ ...s, approver_dtsigned: e.target.value }))} /></Grid>
                      </Grid>
                    )}

                    {/* Previous FAAS */}
                    {((formData.rpu_type?.toUpperCase() !== 'BLDG' && activeTab === 3) || (formData.rpu_type?.toUpperCase() === 'BLDG' && activeTab === 6)) && (
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={4}>
                          <Autocomplete
                            freeSolo
                            options={faasOptions}
                            getOptionLabel={(opt) => typeof opt === 'string' ? opt : opt.tdno || ''}
                            inputValue={formData.prevtdno || ''}
                            onInputChange={(e, val) => setFormData(prev => ({ ...prev, prevtdno: val }))}
                            onChange={handlePrevFaasSelect}
                            renderInput={(params) => <TextField {...params} fullWidth size="small" variant="standard" label="Previous TD No." />}
                          />
                        </Grid>
                        <Grid item xs={12} sm={4}><TextField fullWidth size="small" variant="standard" label="Previous PIN" value={formData.prev_pin || ''} onChange={handleChange('prev_pin')} /></Grid>
                        <Grid item xs={12} sm={4}></Grid>
                        <Grid item xs={12} sm={12}><TextField fullWidth size="small" variant="standard" label="Previous Owner" value={formData.prev_owner || ''} onChange={handleChange('prev_owner')} /></Grid>
                        <Grid item xs={12} sm={12}><TextField fullWidth size="small" variant="standard" label="Previous Administrator" value={formData.prev_administrator || ''} onChange={handleChange('prev_administrator')} /></Grid>
                        
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Prev. Assessed Val" value={formData.prev_assessed_value || ''} onChange={handleChange('prev_assessed_value')} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Prev. Market Val" value={formData.prev_market_value || ''} onChange={handleChange('prev_market_value')} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Prev. Area (sqm)" value={formData.prev_area_sqm || ''} onChange={handleChange('prev_area_sqm')} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" variant="standard" label="Prev. Area (ha)" value={formData.prev_area_hectare || ''} onChange={handleChange('prev_area_hectare')} /></Grid>
                      </Grid>
                    )}

                    {/* Memoranda */}
                    {((formData.rpu_type?.toUpperCase() !== 'BLDG' && activeTab === 4) || (formData.rpu_type?.toUpperCase() === 'BLDG' && activeTab === 7)) && (
                      <Grid container spacing={2}>
                        <Grid item xs={12}><TextField fullWidth size="small" variant="standard" multiline rows={4} label="Memoranda" value={formData.memoranda || ''} onChange={handleChange('memoranda')} /></Grid>
                      </Grid>
                    )}

                    {/* Restrictions */}
                    {formData.rpu_type?.toUpperCase() === 'BLDG' && activeTab === 8 && (
                      <Grid container spacing={2}>
                        <Grid item xs={12}><TextField fullWidth size="small" variant="standard" multiline rows={4} label="Restrictions" value={formData.restrictions || ''} onChange={handleChange('restrictions')} /></Grid>
                      </Grid>
                    )}
                  </CardContent>
                </Card>
              </Box>
            )}

            <Box sx={{ display: 'flex', justifyContent: 'flex-end', gap: 2, mt: 2 }}>
              <Button onClick={onClose} variant="outlined" disabled={loading}>Cancel</Button>
              {activeStep === 0 ? (
                <Button variant="contained" onClick={handleNext}>Next</Button>
              ) : (
                <>
                  {!isEditing && <Button onClick={() => setActiveStep(0)} variant="outlined" disabled={loading}>Back</Button>}
                  <Button onClick={handleSubmit} variant="contained" disabled={loading}>
                    {loading ? 'Saving...' : isEditing ? 'Update Record' : 'Save Draft'}
                  </Button>
                </>
              )}
            </Box>
          </motion.div>
        </DialogContent>
      </Dialog>
      <Snackbar open={toast.open} autoHideDuration={3000} onClose={() => setToast(prev => ({ ...prev, open: false }))}>
        <Alert severity={toast.severity}>{toast.message}</Alert>
      </Snackbar>
    </>
  );
};

export default EtracsPropertyFormModal;
