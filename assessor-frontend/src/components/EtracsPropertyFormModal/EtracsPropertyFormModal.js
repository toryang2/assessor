import React, { useState, useEffect } from 'react';
import {
  Box, TextField, Grid, FormControl, InputLabel, Select, MenuItem, Button, Typography, Alert,
  Divider, Card, CardContent, Snackbar, Dialog, DialogTitle, DialogContent, Switch, FormControlLabel,
  Stepper, Step, StepLabel, RadioGroup, Radio, Autocomplete, Tabs, Tab, TableContainer, Table,
  TableHead, TableRow, TableCell, TableBody, Paper, CircularProgress
} from '@mui/material';
import { motion } from 'framer-motion';
import { etracsService, apiService } from '../../utils/api';

const defaultFormData = {
  // FAAS fields
  tdno: '', utdno: '', pin_type: 'NEW', section: '', parcel: '', claim_no: '',
  txntype_code: 'GR', effectivity_year: '', effectivity_qtr: '', owner_name: '', owner_address: '',
  administrator_name: '', administrator_address: '', beneficiary_name: '', beneficiary_address: '',
  pin: '', title_type: '', title_no: '', title_date: '', prevtdno: '', prev_owner: '',
  prev_assessed_value: '', prev_market_value: '', prev_area_hectare: '', prev_area_sqm: '',
  prev_effectivity: '', memoranda: '', back_tax_years: 0, ry_ordinance_no: '', ry_ordinance_date: '',
  date_approved: '', year_issued: '', public_land: 0,
  // RPU fields
  rpu_type: 'LAND', classification: '', ry: '', total_market_value: '', total_assessed_value: '', taxable: 1,
  // Real Property fields
  cadastral_lot_no: '', survey_no: '', block_no: '', barangay: '', barangayid: '', municipality: '', province: '',
  total_area_hectare: '', total_area_sqm: '', north: '', south: '', east: '', west: '',
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
          etracsService.getRpuDetail(property.rpu_id).then(res => {
            setRpuData(res);
            setAssessments(res.assessments || []);
          }).catch(console.error);
        }
        if (property.id) {
          etracsService.getFaasSignatory(property.id).then(res => setSignatory(res)).catch(console.error);
        }
      } else {
        setFormData({ ...defaultFormData });
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

  const handlePrevFaasSelect = (event, newValue) => {
    if (newValue) {
      if (typeof newValue === 'string') {
        setFormData(prev => ({ ...prev, prevtdno: newValue }));
        return;
      }
      setFormData(prev => ({
        ...prev,
        prevtdno: newValue.tdno || '',
        prev_owner: newValue.owner_name || newValue.taxpayer_name || '',
        prev_assessed_value: newValue.total_assessed_value || '',
        prev_market_value: newValue.total_market_value || '',
        prev_area_hectare: newValue.total_area_hectare || '',
        prev_area_sqm: newValue.total_area_sqm || '',
      }));
    } else {
      setFormData(prev => ({ ...prev, prevtdno: '' }));
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

  const handleSubmit = async () => {
    if (!formData.tdno || !formData.owner_name) {
      setError('TD Number and Owner Name are required');
      return;
    }
    try {
      setLoading(true);
      if (isEditing) {
        await etracsService.updateFaas(property.id, formData);
        if (Object.keys(signatory).length > 0) {
          await etracsService.updateFaasSignatory(property.id, signatory);
        }
        setToast({ open: true, message: 'Updated', severity: 'success' });
      } else {
        const res = await etracsService.createFaas(formData);
        if (res && res.id && Object.keys(signatory).length > 0) {
          await etracsService.updateFaasSignatory(res.id, signatory);
        }
        setToast({ open: true, message: 'Created', severity: 'success' });
      }
      onSave?.();
    } catch (err) {
      setError(err.message || 'Failed to save record');
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
                      <Tab label={`${formData.rpu_type} Detail`} />
                      <Tab label="Assessment" />
                      <Tab label="Signatories" />
                      <Tab label="Superseded" />
                      <Tab label="Memoranda" />
                    </Tabs>
                  </Box>

                  <CardContent sx={{ minHeight: 300, bgcolor: '#fff' }}>
                    
                    {/* RPU Detail Tab */}
                    {activeTab === 0 && (
                      <Box>
                        {rpuData === null ? (
                          <Box textAlign="center" py={5}><CircularProgress size={30} /><Typography mt={2}>Loading RPU Details...</Typography></Box>
                        ) : (
                          <Box>
                            {/* LAND RPU Detail */}
                            {formData.rpu_type === 'LAND' && rpuData?.landdetail && (
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
                            
                            {/* BLDG RPU Detail */}
                            {formData.rpu_type === 'BLDG' && rpuData?.floors && (
                              <TableContainer component={Paper} variant="outlined">
                                <Table size="small">
                                  <TableHead><TableRow><TableCell>Floor #</TableCell><TableCell>Area</TableCell><TableCell>Use</TableCell><TableCell>Market Value</TableCell></TableRow></TableHead>
                                  <TableBody>
                                    {rpuData.floors.map((row, i) => (
                                      <TableRow key={i}>
                                        <TableCell>{row.floorno}</TableCell>
                                        <TableCell>{row.area} sqm</TableCell>
                                        <TableCell>{row.bldgusename}</TableCell>
                                        <TableCell>{Number(row.marketvalue).toLocaleString()}</TableCell>
                                      </TableRow>
                                    ))}
                                  </TableBody>
                                </Table>
                              </TableContainer>
                            )}

                            {/* MACH Detail */}
                            {formData.rpu_type === 'MACH' && rpuData?.machines && (
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

                    {/* Assessment Tab */}
                    {activeTab === 1 && (
                      <Box>
                        <TableContainer component={Paper} variant="outlined">
                          <Table size="small">
                            <TableHead>
                              <TableRow sx={{ bgcolor: '#f0f0f0' }}>
                                <TableCell>Actual Use</TableCell>
                                <TableCell>Class</TableCell>
                                <TableCell>Area</TableCell>
                                <TableCell>Market Value</TableCell>
                                <TableCell>Assess Level</TableCell>
                                <TableCell>Assessed Value</TableCell>
                              </TableRow>
                            </TableHead>
                            <TableBody>
                              {assessments.map((r, i) => (
                                <TableRow key={i}>
                                  <TableCell>{r.actualuse || 'N/A'}</TableCell>
                                  <TableCell>{r.classname || r.classcode}</TableCell>
                                  <TableCell>{r.areasqm ? `${r.areasqm} sqm` : `${r.areaha} ha`}</TableCell>
                                  <TableCell>₱{Number(r.marketvalue).toLocaleString()}</TableCell>
                                  <TableCell>{Number(r.assesslevel)}%</TableCell>
                                  <TableCell sx={{ fontWeight: 'bold' }}>₱{Number(r.assessedvalue).toLocaleString()}</TableCell>
                                </TableRow>
                              ))}
                              {assessments.length === 0 && <TableRow><TableCell colSpan={6} align="center">No assessment records found.</TableCell></TableRow>}
                            </TableBody>
                          </Table>
                        </TableContainer>
                        <Grid container spacing={2} sx={{ mt: 2 }}>
                          <Grid item xs={6}><Typography variant="subtitle2">Total Market Value: ₱{Number(formData.total_market_value || 0).toLocaleString()}</Typography></Grid>
                          <Grid item xs={6}><Typography variant="subtitle2" color="primary.main">Total Assessed Value: ₱{Number(formData.total_assessed_value || 0).toLocaleString()}</Typography></Grid>
                        </Grid>
                      </Box>
                    )}

                    {/* Signatories Tab */}
                    {activeTab === 2 && (
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" label="Appraiser" value={signatory.appraiser_name || ''} onChange={(e) => setSignatory(s => ({ ...s, appraiser_name: e.target.value }))} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" label="Appraiser Date" value={signatory.appraiser_dtsigned || ''} onChange={(e) => setSignatory(s => ({ ...s, appraiser_dtsigned: e.target.value }))} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" label="Recommender" value={signatory.recommender_name || ''} onChange={(e) => setSignatory(s => ({ ...s, recommender_name: e.target.value }))} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" label="Approver (Assessor)" value={signatory.approver_name || ''} onChange={(e) => setSignatory(s => ({ ...s, approver_name: e.target.value }))} /></Grid>
                      </Grid>
                    )}

                    {/* Superseded FAAS */}
                    {activeTab === 3 && (
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={4}>
                          <Autocomplete
                            freeSolo
                            options={faasOptions}
                            getOptionLabel={(opt) => typeof opt === 'string' ? opt : opt.tdno || ''}
                            inputValue={formData.prevtdno || ''}
                            onInputChange={(e, val) => setFormData(prev => ({ ...prev, prevtdno: val }))}
                            onChange={handlePrevFaasSelect}
                            renderInput={(params) => <TextField {...params} fullWidth size="small" label="Previous TD No." />}
                          />
                        </Grid>
                        <Grid item xs={12} sm={8}><TextField fullWidth size="small" label="Previous Owner" value={formData.prev_owner} onChange={handleChange('prev_owner')} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" label="Prev. Assessed Val" value={formData.prev_assessed_value} onChange={handleChange('prev_assessed_value')} /></Grid>
                        <Grid item xs={12} sm={6}><TextField fullWidth size="small" label="Prev. Area (sqm)" value={formData.prev_area_sqm} onChange={handleChange('prev_area_sqm')} /></Grid>
                      </Grid>
                    )}

                    {/* Memoranda */}
                    {activeTab === 4 && (
                      <Grid container spacing={2}>
                        <Grid item xs={12}><TextField fullWidth size="small" multiline rows={4} label="Memoranda" value={formData.memoranda} onChange={handleChange('memoranda')} /></Grid>
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
                    {loading ? 'Saving...' : isEditing ? 'Update Record' : 'Create Record'}
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
