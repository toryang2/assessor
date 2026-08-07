import React, { useState } from 'react';
import {
  Box, TextField, Grid, FormControl, InputLabel, Select, MenuItem, Typography,
  TableContainer, Table, TableHead, TableRow, TableCell, TableBody, Paper, Button,
  IconButton, Checkbox, FormControlLabel, Autocomplete, ThemeProvider, createTheme
} from '@mui/material';
import DeleteIcon from '@mui/icons-material/Delete';
import AddIcon from '@mui/icons-material/Add';
import SearchIcon from '@mui/icons-material/Search';

// Create a very compact theme specifically for this component to match legacy Desktop UI
const compactTheme = createTheme({
  components: {
    MuiTextField: {
      defaultProps: { variant: 'standard', size: 'small', InputLabelProps: { shrink: true } },
      styleOverrides: { root: { '& .MuiInputBase-root': { fontSize: '0.8rem', padding: 0 } } }
    },
    MuiSelect: {
      defaultProps: { variant: 'standard', size: 'small' },
      styleOverrides: { root: { fontSize: '0.8rem' } }
    },
    MuiInputLabel: {
      styleOverrides: { root: { fontSize: '0.8rem' } }
    },
    MuiTableCell: {
      styleOverrides: { root: { padding: '4px 8px', fontSize: '0.75rem' } }
    },
    MuiButton: {
      styleOverrides: { root: { textTransform: 'none', padding: '2px 8px' } }
    }
  }
});

const BuildingFaasEditor = ({ building, setBuilding, lookups, classifications, activeTab }) => {
  const defaultBldg = {
    bldgclass: '', bldgkindbucc_objid: '', bldgtype_objid: '', assesslevel: 0, depreciation: 0,
    floorcount: 1, condominium: 0, landrpuid: '', basefloorarea: 0, totalfloorarea: 0,
    floors: [], structures: [], uses: [], percentcompleted: 100, dateconstructed: '',
    datecompleted: '', dateoccupied: '', bldgpermitno: '', bldgpermitdate: '',
    occupancypermitno: '', occupancypermitdate: '', cctno: '', ccino: '', additionalinfo: '',
    bldg_age: 0, effective_age: 0, cdurating: '', depreciation_value: 0, sworn_statement: 0,
    sworn_amount: 0, use_sworn_amount: 0,
  };
  
  // Merge any incoming building properties with defaults so arrays/fields are never undefined
  const bldg = { ...defaultBldg, ...(building || {}) };

  const updateBldg = (field, value) => {
    setBuilding({ ...bldg, [field]: value });
  };

  const [selectedFloor, setSelectedFloor] = useState(1);

  if (!lookups) return <Typography>Loading Lookups...</Typography>;

  const { materials = [], types = [], actualUses = [] } = lookups;

  console.log("DEBUG BLDG CLASSIFICATION:", bldg.classification_objid);
  console.log("DEBUG CLASSIFICATIONS ARRAY:", classifications);

  // TAB 0: General Information
  if (activeTab === 0) {
    return (
      <ThemeProvider theme={compactTheme}>
        <Box sx={{ mt: 2 }}>
          <Grid container spacing={4}>
            {/* Left Column */}
            <Grid item xs={12} md={7}>
              <Grid container spacing={1.5}>
                <Grid item xs={12} sm={6}>
                  <FormControl fullWidth size="small" variant="standard">
                    <InputLabel shrink>Classification *</InputLabel>
                    <Select value={bldg.classification_objid || ''} onChange={(e) => updateBldg('classification_objid', e.target.value)}>
                      {classifications.map((c, i) => <MenuItem key={i} value={c.objid}>{c.name}</MenuItem>)}
                    </Select>
                  </FormControl>
                </Grid>
                <Grid item xs={12} sm={6}>
                  <FormControl fullWidth size="small" variant="standard">
                    <InputLabel shrink>Building Class</InputLabel>
                    <Select value={bldg.bldgclass || ''} onChange={(e) => updateBldg('bldgclass', e.target.value)}>
                      <MenuItem value=""><em>None</em></MenuItem>
                      <MenuItem value="CLASS A">CLASS A</MenuItem>
                      <MenuItem value="CLASS B">CLASS B</MenuItem>
                      <MenuItem value="CLASS C">CLASS C</MenuItem>
                      <MenuItem value="CLASS D">CLASS D</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>

                <Grid item xs={12} sm={6}><TextField fullWidth type="number" label="Percent Completed *" value={bldg.percentcompleted || ''} onChange={(e) => updateBldg('percentcompleted', e.target.value)} /></Grid>
                <Grid item xs={12} sm={6}></Grid>

                <Grid item xs={12} sm={6}><TextField fullWidth type="date" InputLabelProps={{ shrink: true }} label="Date Constructed" value={(bldg.dtconstructed || '').split(' ')[0]} onChange={(e) => updateBldg('dtconstructed', e.target.value)} /></Grid>
                <Grid item xs={12} sm={6}></Grid>

                <Grid item xs={12} sm={6}><TextField fullWidth type="date" InputLabelProps={{ shrink: true }} label="Date Completed" value={(bldg.dtcompleted || '').split(' ')[0]} onChange={(e) => updateBldg('dtcompleted', e.target.value)} /></Grid>
                <Grid item xs={12} sm={6}></Grid>

                <Grid item xs={12} sm={6}><TextField fullWidth type="date" InputLabelProps={{ shrink: true }} label="Date Occupied" value={(bldg.dtoccupied || '').split(' ')[0]} onChange={(e) => updateBldg('dtoccupied', e.target.value)} /></Grid>
                <Grid item xs={12} sm={6}></Grid>

                <Grid item xs={12} sm={6}><TextField fullWidth label="Bldg Permit No." value={bldg.permitno || ''} onChange={(e) => updateBldg('permitno', e.target.value)} /></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="date" InputLabelProps={{ shrink: true }} label="Date Issued" value={(bldg.permitdate || '').split(' ')[0]} onChange={(e) => updateBldg('permitdate', e.target.value)} /></Grid>

                <Grid item xs={12} sm={6}><TextField fullWidth label="Occupancy Cert. No." value={bldg.occpermitno || ''} onChange={(e) => updateBldg('occpermitno', e.target.value)} /></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="date" InputLabelProps={{ shrink: true }} label="Date Issued" value={(bldg.dtcertoccupancy || '').split(' ')[0]} onChange={(e) => updateBldg('dtcertoccupancy', e.target.value)} /></Grid>

                <Grid item xs={12}><TextField fullWidth label="Condominium Certificate of Title" value={bldg.condocerttitle || ''} onChange={(e) => updateBldg('condocerttitle', e.target.value)} /></Grid>
                <Grid item xs={12}><TextField fullWidth label="Certificate of Completion Issuance" value={bldg.dtcertcompletion || ''} onChange={(e) => updateBldg('dtcertcompletion', e.target.value)} /></Grid>
                <Grid item xs={12}><TextField fullWidth multiline rows={2} label="Additional Information" value={bldg.additionalinfo || ''} onChange={(e) => updateBldg('additionalinfo', e.target.value)} /></Grid>
              </Grid>
            </Grid>

            {/* Right Column */}
            <Grid item xs={12} md={5}>
              <Grid container spacing={1} sx={{ bgcolor: '#f5f5f5', p: 1.5, border: '1px solid #ccc' }}>
                <Grid item xs={12} sm={6}><Typography variant="body2" sx={{ pt: 1, textAlign: 'right', fontSize: '0.8rem' }}>Building Age : *</Typography></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="number" value={bldg.bldgage || 0} onChange={(e) => updateBldg('bldgage', e.target.value)} /></Grid>
                
                <Grid item xs={12} sm={6}><Typography variant="body2" sx={{ pt: 1, textAlign: 'right', fontSize: '0.8rem' }}>Effective Age : *</Typography></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="number" value={bldg.effectiveage || 0} onChange={(e) => updateBldg('effectiveage', e.target.value)} /></Grid>

                <Grid item xs={12} sm={6}><Typography variant="body2" sx={{ pt: 1, textAlign: 'right', fontSize: '0.8rem' }}>CDU Rating :</Typography></Grid>
                <Grid item xs={12} sm={6}>
                  <Select fullWidth value={bldg.cdurating || ''} onChange={(e) => updateBldg('cdurating', e.target.value)}>
                    <MenuItem value="excellent">excellent</MenuItem>
                    <MenuItem value="good">good</MenuItem>
                    <MenuItem value="average">average</MenuItem>
                    <MenuItem value="fair">fair</MenuItem>
                    <MenuItem value="poor">poor</MenuItem>
                    <MenuItem value="very poor">very poor</MenuItem>
                  </Select>
                </Grid>

                <Grid item xs={12} sm={6}><Typography variant="body2" sx={{ pt: 1, textAlign: 'right', fontSize: '0.8rem' }}>Depreciation (%) : *</Typography></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="number" value={bldg.depreciation || 0} onChange={(e) => updateBldg('depreciation', e.target.value)} /></Grid>

                <Grid item xs={12} sm={6}><Typography variant="body2" sx={{ pt: 1, textAlign: 'right', fontSize: '0.8rem' }}>Depreciation Value :</Typography></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="number" disabled value={bldg.depreciationvalue || 0} /></Grid>

                <Grid item xs={12} sm={6}><Typography variant="body2" sx={{ pt: 1, textAlign: 'right', fontSize: '0.8rem', fontWeight: 'bold' }}>Floor Count : *</Typography></Grid>
                <Grid item xs={12} sm={6}><TextField fullWidth type="number" value={bldg.floorcount || 1} onChange={(e) => updateBldg('floorcount', e.target.value)} /></Grid>
              </Grid>

              {/* Sworn Statement Panel */}
              <Box sx={{ mt: 2, border: '1px solid #ccc' }}>
                <Box sx={{ bgcolor: '#d9d9d9', p: 0.5, px: 1, borderBottom: '1px solid #ccc' }}>
                  <Typography variant="body2" sx={{ fontWeight: 'bold', fontSize: '0.8rem' }}>Sworn Statement Information</Typography>
                </Box>
                <Box sx={{ p: 1.5 }}>
                  <FormControlLabel control={<Checkbox size="small" checked={!!bldg.sworn_statement} onChange={(e) => updateBldg('sworn_statement', e.target.checked ? 1 : 0)} />} label={<Typography sx={{fontSize: '0.8rem'}}>Sworn Statement</Typography>} />
                  <Box sx={{ display: 'flex', alignItems: 'center', mt: 0.5, ml: 4 }}>
                    <Typography variant="body2" sx={{ mr: 1, fontSize: '0.8rem' }}>Sworn Amount :</Typography>
                    <TextField type="number" value={bldg.sworn_amount || 0} onChange={(e) => updateBldg('sworn_amount', e.target.value)} />
                  </Box>
                  <FormControlLabel sx={{ mt: 0.5, ml: 4 }} control={<Checkbox size="small" checked={!!bldg.use_sworn_amount} onChange={(e) => updateBldg('use_sworn_amount', e.target.checked ? 1 : 0)} />} label={<Typography sx={{fontSize: '0.8rem'}}>Use Sworn Amount?</Typography>} />
                </Box>
              </Box>
            </Grid>
          </Grid>
        </Box>
      </ThemeProvider>
    );
  }

  // TAB 1: Property Appraisal
  if (activeTab === 1) {
    const handleAddUse = () => {
      setBuilding({ ...bldg, uses: [...bldg.uses, { actualuse_objid: '', area: 0, basemarketvalue: 0, depreciationvalue: 0, marketvalue: 0, assesslevel: 0 }] });
    };
    const handleUpdateUse = (index, field, value) => {
      const newUses = [...bldg.uses];
      newUses[index] = { ...newUses[index], [field]: value };
      setBuilding({ ...bldg, uses: newUses });
    };
    const handleRemoveUse = (index) => {
      const newUses = [...bldg.uses];
      newUses.splice(index, 1);
      setBuilding({ ...bldg, uses: newUses });
    };

    const handleAddFloor = () => {
      setBuilding({ ...bldg, floors: [...bldg.floors, { floorno: bldg.floors.length + 1, area: 0, basevalue: 0, adjustment: 0 }] });
    };
    const handleUpdateFloor = (index, field, value) => {
      const newFloors = [...bldg.floors];
      newFloors[index] = { ...newFloors[index], [field]: value };
      setBuilding({ ...bldg, floors: newFloors });
    };
    const handleRemoveFloor = (index) => {
      const newFloors = [...bldg.floors];
      newFloors.splice(index, 1);
      setBuilding({ ...bldg, floors: newFloors });
    };

    return (
      <ThemeProvider theme={compactTheme}>
        <Box sx={{ mt: 1 }}>
          <TableContainer component={Paper} variant="outlined" sx={{ mb: 2, borderRadius: 0 }}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#e0e0e0' }}>
                  <TableCell>Classification *</TableCell>
                  <TableCell>Structural Type *</TableCell>
                  <TableCell>Building Kind *</TableCell>
                  <TableCell>Base Floor Area *</TableCell>
                  <TableCell>Floor Area</TableCell>
                  <TableCell>Base Value *</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                <TableRow>
                  <TableCell>{classifications.find(c => c.objid === bldg.classification_objid)?.name || ''}</TableCell>
                  <TableCell>
                    <Select fullWidth value={bldg.bldgtype_objid || ''} onChange={(e) => updateBldg('bldgtype_objid', e.target.value)}>
                      <MenuItem value=""><em>None</em></MenuItem>
                      {types.map((t) => <MenuItem key={t.objid} value={t.objid}>{t.name}</MenuItem>)}
                    </Select>
                  </TableCell>
                  <TableCell>
                    <Select fullWidth value={bldg.bldgkindbucc_objid || ''} onChange={(e) => updateBldg('bldgkindbucc_objid', e.target.value)}>
                      <MenuItem value=""><em>None</em></MenuItem>
                      <MenuItem value="SWIMMING POOL">SWIMMING POOL</MenuItem>
                    </Select>
                  </TableCell>
                  <TableCell><TextField type="number" fullWidth value={bldg.basefloorarea || 0} onChange={(e) => updateBldg('basefloorarea', e.target.value)} /></TableCell>
                  <TableCell><TextField type="number" fullWidth value={bldg.totalfloorarea || 0} onChange={(e) => updateBldg('totalfloorarea', e.target.value)} /></TableCell>
                  <TableCell><TextField type="number" fullWidth value={bldg.basevalue || 0} disabled /></TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </TableContainer>

          <Box sx={{ display: 'flex', mb: 0 }}>
            <Box sx={{ p: 0.5, px: 2, bgcolor: '#e0e0e0', border: '1px solid #ccc', borderBottom: 0 }}>
              <Typography variant="body2" sx={{ fontWeight: 'bold', fontSize: '0.8rem' }}>Actual Uses and Floor Information</Typography>
            </Box>
          </Box>
          <TableContainer component={Paper} variant="outlined" sx={{ mb: 2, borderRadius: 0 }}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>Actual Use *</TableCell>
                  <TableCell>Tax?</TableCell>
                  <TableCell>Area</TableCell>
                  <TableCell>Base Market Value</TableCell>
                  <TableCell>Depreciation</TableCell>
                  <TableCell>Adjustment</TableCell>
                  <TableCell>Market Value</TableCell>
                  <TableCell>Additional Info</TableCell>
                  <TableCell><IconButton size="small" onClick={handleAddUse} color="primary" sx={{p:0}}><AddIcon fontSize="small"/></IconButton></TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {bldg.uses.map((use, idx) => (
                  <TableRow key={idx}>
                    <TableCell>
                      <Select fullWidth value={use.actualuse_objid || ''} onChange={(e) => handleUpdateUse(idx, 'actualuse_objid', e.target.value)}>
                        <MenuItem value=""><em>None</em></MenuItem>
                        {actualUses.map((au) => <MenuItem key={au.objid} value={au.objid}>{au.name}</MenuItem>)}
                      </Select>
                    </TableCell>
                    <TableCell><Checkbox size="small" sx={{p:0}}/></TableCell>
                    <TableCell><TextField type="number" value={use.area || 0} onChange={(e) => handleUpdateUse(idx, 'area', e.target.value)} /></TableCell>
                    <TableCell><TextField type="number" value={use.basemarketvalue || 0} onChange={(e) => handleUpdateUse(idx, 'basemarketvalue', e.target.value)} /></TableCell>
                    <TableCell><TextField type="number" value={use.depreciationvalue || 0} onChange={(e) => handleUpdateUse(idx, 'depreciationvalue', e.target.value)} /></TableCell>
                    <TableCell><TextField type="number" value={use.adjustment || 0} disabled /></TableCell>
                    <TableCell><TextField type="number" value={use.marketvalue || 0} onChange={(e) => handleUpdateUse(idx, 'marketvalue', e.target.value)} /></TableCell>
                    <TableCell><TextField value={''} onChange={(e) => null} /></TableCell>
                    <TableCell><IconButton size="small" onClick={() => handleRemoveUse(idx)} color="error" sx={{p:0}}><DeleteIcon fontSize="small"/></IconButton></TableCell>
                  </TableRow>
                ))}
                {bldg.uses.length === 0 && <TableRow><TableCell colSpan={9} align="center">No actual uses added</TableCell></TableRow>}
              </TableBody>
            </Table>
          </TableContainer>

          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 0 }}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#f5f5f5' }}>
                  <TableCell>Floor No.</TableCell>
                  <TableCell>Area</TableCell>
                  <TableCell>Adjustment</TableCell>
                  <TableCell><IconButton size="small" onClick={handleAddFloor} color="primary" sx={{p:0}}><AddIcon fontSize="small"/></IconButton></TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {bldg.floors.map((fl, idx) => (
                  <TableRow key={idx}>
                    <TableCell><TextField type="number" value={fl.floorno || 1} onChange={(e) => handleUpdateFloor(idx, 'floorno', e.target.value)} /></TableCell>
                    <TableCell><TextField type="number" value={fl.area || 0} onChange={(e) => handleUpdateFloor(idx, 'area', e.target.value)} /></TableCell>
                    <TableCell><TextField type="number" value={fl.adjustment || 0} onChange={(e) => handleUpdateFloor(idx, 'adjustment', e.target.value)} /></TableCell>
                    <TableCell><IconButton size="small" onClick={() => handleRemoveFloor(idx)} color="error" sx={{p:0}}><DeleteIcon fontSize="small"/></IconButton></TableCell>
                  </TableRow>
                ))}
                {bldg.floors.length === 0 && <TableRow><TableCell colSpan={4} align="center">No floors added</TableCell></TableRow>}
              </TableBody>
            </Table>
          </TableContainer>
        </Box>
      </ThemeProvider>
    );
  }

  // TAB 2: Structural Materials
  if (activeTab === 2) {
    const handleUpdateStructureMaterial = (structureType, materialId) => {
      let newStructs = [...bldg.structures];
      const existingIdx = newStructs.findIndex(s => s.structure_objid === structureType && s.floor === selectedFloor);
      if (existingIdx >= 0) {
        if (!materialId) {
          newStructs.splice(existingIdx, 1);
        } else {
          newStructs[existingIdx].material_objid = materialId;
        }
      } else if (materialId) {
        newStructs.push({ structure_objid: structureType, material_objid: materialId, floor: selectedFloor });
      }
      setBuilding({ ...bldg, structures: newStructs });
    };

    const commonStructures = ['TRUSS', 'ROOF', 'FLOORING', 'EXTERIOR WALLS', 'WALL FINISH', 'PARTITION', 'DOORS', 'WINDOWS', 'STAIRS', 'CEILING', 'WALLING', 'SWIMMING POOL', 'COLUMNS', 'FOUNDATION'];

    return (
      <ThemeProvider theme={compactTheme}>
        <Box sx={{ mt: 1 }}>
          <Box sx={{ display: 'flex', gap: 2, mb: 1.5, alignItems: 'center' }}>
            <Typography variant="body2" sx={{ ml: 1, fontSize: '0.8rem' }}>Floor Count: <strong>{bldg.floorcount}</strong></Typography>
            <Typography variant="body2" sx={{ color: 'red', ml: 1 }}>*</Typography>
            <FormControl sx={{ minWidth: 120 }} variant="standard">
              <InputLabel>Floor No.</InputLabel>
              <Select value={selectedFloor} onChange={(e) => setSelectedFloor(e.target.value)}>
                {[...Array(Number(bldg.floorcount) || 1)].map((_, i) => (
                  <MenuItem key={i + 1} value={i + 1}>{i + 1}</MenuItem>
                ))}
              </Select>
            </FormControl>
          </Box>
          
          <TableContainer component={Paper} variant="outlined" sx={{ maxWidth: 600, borderRadius: 0 }}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#e0e0e0' }}>
                  <TableCell>Structure</TableCell>
                  <TableCell>Material</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {commonStructures.map(struct => {
                  const existing = bldg.structures.find(s => s.structure_objid === struct && s.floor === selectedFloor);
                  return (
                    <TableRow key={struct}>
                      <TableCell sx={{ fontWeight: 'bold' }}>{struct}</TableCell>
                      <TableCell>
                        <Select 
                          fullWidth 
                          displayEmpty
                          value={existing?.material_objid || ''} 
                          onChange={(e) => handleUpdateStructureMaterial(struct, e.target.value)}
                          renderValue={(selected) => {
                            if (!selected) return <span style={{color:'#aaa'}}><SearchIcon fontSize="small" sx={{verticalAlign:'middle', mr:1, fontSize:'0.9rem'}}/></span>;
                            const mat = materials.find(m => m.objid === selected);
                            return <span style={{display: 'flex', alignItems: 'center'}}><SearchIcon fontSize="small" sx={{mr:1, fontSize:'0.9rem'}}/> {mat ? mat.name : selected}</span>;
                          }}
                        >
                          <MenuItem value=""><em>None</em></MenuItem>
                          {materials.map(m => (
                            <MenuItem key={m.objid} value={m.objid}>{m.name}</MenuItem>
                          ))}
                        </Select>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>
        </Box>
      </ThemeProvider>
    );
  }

  // TAB 3: Lands
  if (activeTab === 3) {
    return (
      <ThemeProvider theme={compactTheme}>
        <Box sx={{ mt: 1 }}>
          <Typography variant="body2" sx={{ mb: 1, fontSize: '0.8rem' }}>List of lands where the building is also located.</Typography>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 0 }}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: '#e0e0e0' }}>
                  <TableCell>Land PIN</TableCell>
                  <TableCell>TD No.</TableCell>
                  <TableCell>Land Owner</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                <TableRow>
                  <TableCell colSpan={3} align="center" sx={{ py: 2, color: 'text.secondary' }}>No records to display.</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </TableContainer>
        </Box>
      </ThemeProvider>
    );
  }

  return null;
};

export default BuildingFaasEditor;
