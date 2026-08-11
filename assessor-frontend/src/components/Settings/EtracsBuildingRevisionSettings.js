import React, { useState, useEffect } from 'react';
import { Box, Typography, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Paper, CircularProgress, Alert, Tabs, Tab } from '@mui/material';
import { etracsService } from '../../utils/api';

const EtracsBuildingRevisionSettings = () => {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [data, setData] = useState(null);
  const [activeTab, setActiveTab] = useState(0);

  useEffect(() => {
    const fetchSettings = async () => {
      try {
        setLoading(true);
        // By default get latest revision. We can expand this to let user choose revision year
        const result = await etracsService.getBuildingRevisionSettings();
        setData(result);
      } catch (err) {
        console.error(err);
        setError('Failed to load building revision settings from local DB.');
      } finally {
        setLoading(false);
      }
    };
    fetchSettings();
  }, []);

  const handleTabChange = (event, newValue) => {
    setActiveTab(newValue);
  };

  if (loading) return <CircularProgress />;
  if (error) return <Alert severity="error">{error}</Alert>;
  if (!data || !data.setting) return <Alert severity="info">No Building Revision Settings found.</Alert>;

  const { setting, types, unitCosts, classifications, allSettings } = data;

  return (
    <Box>
      <Typography variant="h6" gutterBottom>Edit Building Revision Setting</Typography>
      
      <Paper variant="outlined" sx={{ p: 2, mb: 3, bgcolor: '#f9f9f9' }}>
        <Typography variant="subtitle2" sx={{ mb: 1, fontWeight: 'bold' }}>General Information</Typography>
        <Box sx={{ display: 'grid', gridTemplateColumns: '150px 1fr', gap: 1 }}>
          <Typography variant="body2" color="text.secondary">Revision Year :</Typography>
          <Typography variant="body2">{setting.ry}</Typography>
          
          <Typography variant="body2" color="text.secondary">Ordinance No. :</Typography>
          <Typography variant="body2">{setting.ordinanceno}</Typography>
          
          <Typography variant="body2" color="text.secondary">Ordinance Date :</Typography>
          <Typography variant="body2">{setting.ordinancedate}</Typography>
          
          <Typography variant="body2" color="text.secondary">Applied To :</Typography>
          <Typography variant="body2">{setting.appliedto}</Typography>
          
          <Typography variant="body2" color="text.secondary">Remarks :</Typography>
          <Typography variant="body2">{setting.remarks}</Typography>
        </Box>
      </Paper>

      <Box sx={{ borderBottom: 1, borderColor: 'divider' }}>
        <Tabs value={activeTab} onChange={handleTabChange} size="small">
          <Tab label="Building Types" />
          <Tab label="Kind of Buildings and Unit Value" />
          <Tab label="Assessment Levels (Classifications)" />
        </Tabs>
      </Box>

      {activeTab === 0 && (
        <TableContainer component={Paper} variant="outlined" sx={{ mt: 2, maxHeight: 400 }}>
          <Table size="small" stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell>Code</TableCell>
                <TableCell>Title</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {types.map((type) => (
                <TableRow key={type.objid}>
                  <TableCell>{type.code}</TableCell>
                  <TableCell>{type.name}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {activeTab === 1 && (
        <TableContainer component={Paper} variant="outlined" sx={{ mt: 2, maxHeight: 400 }}>
          <Table size="small" stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell>Bldg Code</TableCell>
                <TableCell>Kind of Building</TableCell>
                <TableCell align="right">Base Value</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {unitCosts.map((cost) => (
                <TableRow key={cost.objid}>
                  <TableCell>{cost.kind_code}</TableCell>
                  <TableCell>{cost.kind_name}</TableCell>
                  <TableCell align="right">{Number(cost.basevalue).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {activeTab === 2 && (
        <TableContainer component={Paper} variant="outlined" sx={{ mt: 2, maxHeight: 400 }}>
          <Table size="small" stickyHeader>
            <TableHead>
              <TableRow>
                <TableCell>Code</TableCell>
                <TableCell>Name</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {classifications.map((cls) => (
                <TableRow key={cls.objid}>
                  <TableCell>{cls.code}</TableCell>
                  <TableCell>{cls.name}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </Box>
  );
};

export default EtracsBuildingRevisionSettings;
