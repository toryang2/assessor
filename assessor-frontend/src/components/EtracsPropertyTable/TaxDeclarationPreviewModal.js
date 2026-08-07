import React, { useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
  Grid,
  Divider,
  CircularProgress
} from '@mui/material';
import { etracsService } from '../../utils/api';

const formatCurrency = (value) => {
  if (value == null || value === '') return '0.00';
  const num = typeof value === 'string' ? parseFloat(value.replace(/,/g, '')) : value;
  if (isNaN(num)) return '0.00';
  return Number(num).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const formatAreaHa = (value) => {
  if (value == null || value === '') return '0.0000';
  const num = typeof value === 'string' ? parseFloat(value.replace(/,/g, '')) : value;
  if (isNaN(num)) return '0.0000';
  return Number(num).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
};

const numberToWords = (amount) => {
  if (amount == null || isNaN(amount)) return '';
  const num = Math.floor(amount);
  const cents = Math.round((amount - num) * 100);
  
  const a = ['','ONE ','TWO ','THREE ','FOUR ', 'FIVE ','SIX ','SEVEN ','EIGHT ','NINE ','TEN ','ELEVEN ','TWELVE ','THIRTEEN ','FOURTEEN ','FIFTEEN ','SIXTEEN ','SEVENTEEN ','EIGHTEEN ','NINETEEN '];
  const b = ['', '', 'TWENTY','THIRTY','FORTY','FIFTY', 'SIXTY','SEVENTY','EIGHTY','NINETY'];
  
  const toWords = (n) => {
    let s = '';
    if (n > 99) {
      s += a[Math.floor(n / 100)] + 'HUNDRED ';
      n %= 100;
    }
    if (n > 0) {
      if (n < 20) {
        s += a[n];
      } else {
        s += b[Math.floor(n / 10)];
        if (n % 10 > 0) s += ' ' + a[n % 10];
        else s += ' ';
      }
    }
    return s;
  };
  
  let word = '';
  if (num === 0) {
    word = 'ZERO ';
  } else {
    let temp = num;
    if (Math.floor(temp / 1000000000) > 0) {
      word += toWords(Math.floor(temp / 1000000000)) + 'BILLION ';
      temp %= 1000000000;
    }
    if (Math.floor(temp / 1000000) > 0) {
      word += toWords(Math.floor(temp / 1000000)) + 'MILLION ';
      temp %= 1000000;
    }
    if (Math.floor(temp / 1000) > 0) {
      word += toWords(Math.floor(temp / 1000)) + 'THOUSAND ';
      temp %= 1000;
    }
    if (temp > 0) {
      word += toWords(temp);
    }
  }
  
  const centStr = cents < 10 ? '0' + cents : cents;
  return `${word}AND ${centStr}/100`.trim();
};

const TaxDeclarationPreviewModal = ({ open, onClose, property }) => {
  const [assessments, setAssessments] = useState([]);
  const [rpuDetail, setRpuDetail] = useState(null);
  const [sigs, setSigs] = useState({});
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (open && property) {
      setLoading(true);
      Promise.all([
        property.rpu_id ? etracsService.getRpuDetail(property.rpu_id) : Promise.resolve({ assessments: [] }),
        property.id ? etracsService.getFaasSignatory(property.id) : Promise.resolve({})
      ]).then(([rpuData, sigData]) => {
        const rpu = rpuData?.data || rpuData || {};
        setAssessments(rpu.assessments || []);
        setRpuDetail(rpu);
        setSigs(sigData || {});
      }).catch(err => {
        console.error("Failed to load preview details", err);
      }).finally(() => {
        setLoading(false);
      });
    }
  }, [open, property]);

  if (!property) return null;

  const totalMarketValue = assessments.reduce((sum, a) => sum + (parseFloat(a.marketvalue) || 0), 0) || property.total_market_value || 0;
  const totalAssessedValue = assessments.reduce((sum, a) => sum + (parseFloat(a.assessedvalue) || 0), 0) || property.total_assessed_value || 0;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth PaperProps={{ sx: { borderRadius: 0 } }}>
      <DialogContent sx={{ p: 4, bgcolor: '#fff', color: '#000', fontFamily: 'Arial, sans-serif', minHeight: 400 }}>
        
        {loading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
            <CircularProgress />
          </Box>
        ) : (
          <Box>
            {/* Header Section */}
            <Box sx={{ textAlign: 'center', mb: 3 }}>
              <Typography variant="subtitle2" sx={{ fontWeight: 'normal', fontSize: '12px' }}>Republic of the Philippines</Typography>
              <Typography variant="subtitle2" sx={{ fontWeight: 'normal', fontSize: '12px' }}>MUNICIPALITY OF KITAOTAO</Typography>
              <Typography variant="subtitle2" sx={{ fontWeight: 'normal', fontSize: '12px' }}>PROVINCE OF BUKIDNON</Typography>
              <Typography variant="h5" sx={{ fontWeight: 'bold', mt: 2 }}>TAX DECLARATION OF REAL PROPERTY</Typography>
            </Box>

            {/* TD No and PIN */}
            <Grid container spacing={2} sx={{ mb: 2 }}>
              <Grid item xs={6}>
                <Box sx={{ display: 'flex', alignItems: 'baseline' }}>
                  <Typography sx={{ fontWeight: 'bold', mr: 2, fontSize: '14px' }}>TD No. :</Typography>
                  <Typography sx={{ flexGrow: 1, borderBottom: '2px solid #000', textAlign: 'center', fontWeight: 'bold', fontSize: '16px' }}>
                    {property.tdno}
                  </Typography>
                </Box>
              </Grid>
              <Grid item xs={6}>
                <Box sx={{ display: 'flex', alignItems: 'baseline' }}>
                  <Typography sx={{ fontWeight: 'bold', mr: 2, fontSize: '14px' }}>Property Identification No. :</Typography>
                  <Typography sx={{ flexGrow: 1, borderBottom: '2px solid #000', textAlign: 'center', fontWeight: 'bold', fontSize: '16px' }}>
                    {property.fullpin || property.pin || property.rp_pin || property.rpu_fullpin || '—'}
                  </Typography>
                </Box>
              </Grid>
            </Grid>

            <Divider sx={{ borderColor: '#000', borderWidth: 1, mb: 1 }} />

            {/* Owner and Admin Info */}
            <Grid container spacing={2}>
              <Grid item xs={8}>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '80px', fontSize: '13px' }}>Owner:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.owner_name}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '80px', fontSize: '13px' }}>Address:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.owner_address || property.taxpayer_address}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ whiteSpace: 'nowrap', fontSize: '13px' }}>Administrator/Beneficial User:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.administrator_name || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ whiteSpace: 'nowrap', fontSize: '13px' }}>Address:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.administrator_address || '—'}</Typography>
                </Box>
              </Grid>
              
              <Grid item xs={4}>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '40px', fontSize: '13px' }}>TIN:</Typography>
                  <Typography sx={{ flexGrow: 1, ml: 1, fontSize: '14px' }}>—</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '100px', fontSize: '13px' }}>Telephone No. :</Typography>
                  <Typography sx={{ flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.taxpayer_telephone_no || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '40px', fontSize: '13px' }}>TIN:</Typography>
                  <Typography sx={{ flexGrow: 1, ml: 1, fontSize: '14px' }}>—</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '100px', fontSize: '13px' }}>Telephone No. :</Typography>
                  <Typography sx={{ flexGrow: 1, ml: 1, fontSize: '14px' }}>—</Typography>
                </Box>
              </Grid>
            </Grid>

            <Divider sx={{ borderColor: '#000', borderWidth: 1, my: 1 }} />

            {/* Location of Property */}
            <Box sx={{ display: 'flex', mb: 2 }}>
              <Typography sx={{ width: '140px', fontSize: '13px' }}>Location of Property:</Typography>
              <Box sx={{ flexGrow: 1, display: 'flex', gap: 2 }}>
                <Box sx={{ flex: 1 }}>
                  <Typography sx={{ borderBottom: '1px solid #000', textAlign: 'center', fontWeight: 'bold', fontSize: '14px', minHeight: '20px' }}>
                    {property.street || '—'}
                  </Typography>
                  <Typography sx={{ fontSize: '10px', textAlign: 'center', fontStyle: 'italic' }}>(Number and Street)</Typography>
                </Box>
                <Box sx={{ flex: 1 }}>
                  <Typography sx={{ borderBottom: '1px solid #000', textAlign: 'center', fontWeight: 'bold', fontSize: '14px', minHeight: '20px' }}>
                    {property.barangay || '—'}
                  </Typography>
                  <Typography sx={{ fontSize: '10px', textAlign: 'center', fontStyle: 'italic' }}>(Barangay/District)</Typography>
                </Box>
                <Box sx={{ flex: 1.5 }}>
                  <Typography sx={{ borderBottom: '1px solid #000', textAlign: 'center', fontWeight: 'bold', fontSize: '14px', minHeight: '20px' }}>
                    KITAOTAO, PROVINCE OF BUKIDNON
                  </Typography>
                  <Typography sx={{ fontSize: '10px', textAlign: 'center', fontStyle: 'italic' }}>(Municipality & Province/City)</Typography>
                </Box>
              </Box>
            </Box>

            <Divider sx={{ borderColor: '#000', borderWidth: 1, my: 1 }} />

            {/* Title and Survey info */}
            <Grid container spacing={2} sx={{ mb: 1 }}>
              <Grid item xs={6}>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ whiteSpace: 'nowrap', fontSize: '13px' }}>OCT/TCT/CLOA No. :</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.title_no || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ whiteSpace: 'nowrap', fontSize: '13px' }}>CCT :</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>—</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ whiteSpace: 'nowrap', fontSize: '13px' }}>Date :</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.title_date || '—'}</Typography>
                </Box>
              </Grid>
              <Grid item xs={6}>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '80px', fontSize: '13px' }}>Survey No. :</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.survey_no || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '80px', fontSize: '13px' }}>Lot No. :</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.cadastral_lot_no || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '80px', fontSize: '13px' }}>Blk. No. :</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.block_no || '—'}</Typography>
                </Box>
              </Grid>
            </Grid>

            {/* Boundaries */}
            <Box sx={{ display: 'flex', mb: 2 }}>
              <Typography sx={{ width: '80px', fontSize: '13px' }}>Boundaries:</Typography>
              <Box sx={{ flexGrow: 1 }}>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '40px', fontSize: '13px' }}>North:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.north || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '40px', fontSize: '13px' }}>East:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.east || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '40px', fontSize: '13px' }}>South:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.south || '—'}</Typography>
                </Box>
                <Box sx={{ display: 'flex', borderBottom: '1px solid #000', mb: 1, pb: 0.5, alignItems: 'center' }}>
                  <Typography sx={{ width: '40px', fontSize: '13px' }}>West:</Typography>
                  <Typography sx={{ fontWeight: 'bold', flexGrow: 1, ml: 1, fontSize: '14px' }}>{property.west || '—'}</Typography>
                </Box>
              </Box>
            </Box>

            <Divider sx={{ borderColor: '#000', borderWidth: 1, my: 1 }} />

            {/* KIND OF PROPERTY ASSESSED */}
            <Typography sx={{ fontSize: '13px', mb: 1 }}>KIND OF PROPERTY ASSESSED :</Typography>
            <Grid container spacing={2} sx={{ mb: 2, px: 2 }}>
              <Grid item xs={6}>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                  <Box sx={{ width: 16, height: 16, border: '1px solid #000', mr: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                    {(property.rpu_type || '').toLowerCase() === 'land' ? 'X' : ''}
                  </Box>
                  <Typography sx={{ fontSize: '13px', width: '100px' }}>LAND</Typography>
                </Box>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 0 }}>
                  <Box sx={{ width: 16, height: 16, border: '1px solid #000', mr: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                    {(property.rpu_type || '').toLowerCase() === 'bldg' ? 'X' : ''}
                  </Box>
                  <Typography sx={{ fontSize: '13px', width: '100px' }}>BUILDING</Typography>
                  <Typography sx={{ fontSize: '13px', mr: 1 }}>No. of Storeys :</Typography>
                  <Box sx={{ borderBottom: '1px solid #000', flexGrow: 1, textAlign: 'left', px: 1, fontSize: '13px', minHeight: '20px' }}>
                    {rpuDetail?.subtype?.floorcount || ''}
                  </Box>
                </Box>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 1, ml: '124px' }}>
                  <Typography sx={{ fontSize: '13px', mr: 1 }}>Brief Description :</Typography>
                  <Box sx={{ borderBottom: '1px solid #000', flexGrow: 1, textAlign: 'left', px: 1, fontSize: '13px', minHeight: '20px' }}>
                    {rpuDetail?.subtype?.bldgtypename || ''}
                  </Box>
                </Box>
              </Grid>
              <Grid item xs={6}>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                  <Box sx={{ width: 16, height: 16, border: '1px solid #000', mr: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                    {(property.rpu_type || '').toLowerCase() === 'mach' ? 'X' : ''}
                  </Box>
                  <Typography sx={{ fontSize: '13px', width: '100px' }}>MACHINERY</Typography>
                  <Typography sx={{ fontSize: '13px', mr: 1 }}>Brief Description :</Typography>
                  <Box sx={{ borderBottom: '1px solid #000', flexGrow: 1, textAlign: 'left', px: 1, fontSize: '13px', minHeight: '20px' }}>
                    {rpuDetail?.machines?.[0]?.machinename || ''}
                  </Box>
                </Box>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                  <Box sx={{ width: 16, height: 16, border: '1px solid #000', mr: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                    {['misc', 'planttree'].includes((property.rpu_type || '').toLowerCase()) ? 'X' : ''}
                  </Box>
                  <Typography sx={{ fontSize: '13px', width: '100px' }}>OTHERS</Typography>
                  <Typography sx={{ fontSize: '13px', mr: 1 }}>Brief Description :</Typography>
                  <Box sx={{ borderBottom: '1px solid #000', flexGrow: 1, textAlign: 'left', px: 1, fontSize: '13px', minHeight: '20px' }}>
                    {rpuDetail?.miscitems?.[0]?.miscitemname || ''}
                  </Box>
                </Box>
              </Grid>
            </Grid>

            {/* Table for Assessment */}
            <Box sx={{ border: '1px solid #000', mb: 0 }}>
              <Grid container sx={{ borderBottom: '1px solid #000', textAlign: 'center', fontWeight: 'bold', fontSize: '12px' }}>
                <Grid item xs={3} sx={{ p: 1, borderRight: '1px solid #000' }}>Classification</Grid>
                <Grid item xs={2} sx={{ p: 1, borderRight: '1px solid #000' }}>Area</Grid>
                <Grid item xs={2} sx={{ p: 1, borderRight: '1px solid #000' }}>Market Value</Grid>
                <Grid item xs={2} sx={{ p: 1, borderRight: '1px solid #000' }}>Actual Use</Grid>
                <Grid item xs={1.5} sx={{ p: 1, borderRight: '1px solid #000' }}>Assessment Level</Grid>
                <Grid item xs={1.5} sx={{ p: 1 }}>Assessed Value</Grid>
              </Grid>
              
              {assessments.map((assess, idx) => (
                <Grid container key={idx} sx={{ textAlign: 'center', fontSize: '13px', fontWeight: 'bold', borderBottom: idx < assessments.length - 1 ? '1px solid #000' : 'none' }}>
                  <Grid item xs={3} sx={{ p: 1, borderRight: '1px solid #000' }}>{assess.classname || assess.classcode || '—'}</Grid>
                  <Grid item xs={2} sx={{ p: 1, borderRight: '1px solid #000', display: 'flex', justifyContent: 'center', gap: 1 }}>
                    <span>{assess.areasqm ? formatCurrency(assess.areasqm) : formatAreaHa(assess.areaha)}</span> 
                    <span>{assess.areasqm ? 'SQM' : 'HA'}</span>
                  </Grid>
                  <Grid item xs={2} sx={{ p: 1, borderRight: '1px solid #000', textAlign: 'right' }}>{formatCurrency(assess.marketvalue)}</Grid>
                  <Grid item xs={2} sx={{ p: 1, borderRight: '1px solid #000' }}>{assess.actualuse || '—'}</Grid>
                  <Grid item xs={1.5} sx={{ p: 1, borderRight: '1px solid #000' }}>{assess.assesslevel ? `${assess.assesslevel}%` : '—'}</Grid>
                  <Grid item xs={1.5} sx={{ p: 1, textAlign: 'right' }}>{formatCurrency(assess.assessedvalue)}</Grid>
                </Grid>
              ))}
              {assessments.length === 0 && (
                <Grid container sx={{ textAlign: 'center', fontSize: '13px', fontWeight: 'bold' }}>
                  <Grid item xs={12} sx={{ p: 1, fontStyle: 'italic', color: '#666' }}>No assessments recorded</Grid>
                </Grid>
              )}
            </Box>

            <Grid container sx={{ textAlign: 'center', fontSize: '13px', fontWeight: 'bold', mb: 1 }}>
              <Grid item xs={3} sx={{ p: 1, textAlign: 'right' }}>Subtotal :</Grid>
              <Grid item xs={2} sx={{ p: 1, display: 'flex', justifyContent: 'center', gap: 1, borderTop: '1px solid #000', borderBottom: '1px solid #000' }}>
                <span>{formatCurrency(property.total_area_sqm || property.total_area_hectare)}</span>
              </Grid>
              <Grid item xs={2} sx={{ p: 1, textAlign: 'right', borderTop: '1px solid #000', borderBottom: '1px solid #000' }}>{formatCurrency(totalMarketValue)}</Grid>
              <Grid item xs={2} sx={{ p: 1 }}></Grid>
              <Grid item xs={1.5} sx={{ p: 1 }}></Grid>
              <Grid item xs={1.5} sx={{ p: 1, textAlign: 'right', borderTop: '1px solid #000', borderBottom: '1px solid #000' }}>{formatCurrency(totalAssessedValue)}</Grid>
            </Grid>

            {/* Totals */}
            <Box sx={{ display: 'flex', mb: 1, alignItems: 'center' }}>
              <Box sx={{ flexGrow: 1 }} />
              <Typography sx={{ fontSize: '13px', mr: 2 }}>Total Market Value :</Typography>
              <Typography sx={{ fontSize: '14px', fontWeight: 'bold', width: '200px', borderBottom: '1px solid #000', textAlign: 'right', mr: 2 }}>P {formatCurrency(totalMarketValue)}</Typography>
              <Typography sx={{ fontSize: '13px', mr: 2 }}>Total Assessed Value :</Typography>
              <Typography sx={{ fontSize: '14px', fontWeight: 'bold', width: '200px', borderBottom: '1px solid #000', textAlign: 'right' }}>P {formatCurrency(totalAssessedValue)}</Typography>
            </Box>
            <Box sx={{ display: 'flex', mb: 2, alignItems: 'center' }}>
              <Typography sx={{ fontSize: '13px', mr: 2 }}>Amount in words:</Typography>
              <Typography sx={{ fontSize: '13px', fontWeight: 'bold', borderBottom: '1px solid #000', flexGrow: 1, textAlign: 'center' }}>
                {numberToWords(totalAssessedValue)}
              </Typography>
            </Box>

            {/* Status and Approved */}
            <Box sx={{ display: 'flex', mb: 2, alignItems: 'center' }}>
              <Typography sx={{ fontSize: '13px', mr: 1 }}>Taxable</Typography>
              <Box sx={{ width: 16, height: 16, border: '1px solid #000', mr: 2, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                 {property.taxable === '1' || property.taxable === 1 ? 'X' : ''}
              </Box>
              <Typography sx={{ fontSize: '13px', mr: 1 }}>Exempt</Typography>
              <Box sx={{ width: 16, height: 16, border: '1px solid #000', mr: 4, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                 {property.taxable === '0' || property.taxable === 0 ? 'X' : ''}
              </Box>
              
              <Box sx={{ flexGrow: 1 }} />
              <Typography sx={{ fontSize: '13px', mr: 2 }}>Effectivity of Assessment :</Typography>
              <Typography sx={{ fontSize: '14px', fontWeight: 'bold' }}>
                {property.effectivity_year ? `${property.effectivity_qtr ? `Q${property.effectivity_qtr} ` : ''}${property.effectivity_year}` : '—'}
              </Typography>
            </Box>

            {(() => {
              const appraiserName = sigs?.appraiser_name?.trim() || sigs?.provappraiser_name?.trim() || '—';
              const appraiserTitle = sigs?.appraiser_title?.trim() || sigs?.provappraiser_title?.trim() || '—';
              
              const recommenderName = sigs?.recommender_name?.trim() || '';
              const recommenderTitle = sigs?.recommender_title?.trim() || '—';
              
              const approverName = sigs?.approver_name?.trim() || '—';
              const approverTitle = sigs?.approver_title?.trim() || '—';

              const isRecommenderSameAsApprover = recommenderName && approverName && (recommenderName.toLowerCase() + 'x' === approverName.toLowerCase() + 'x');
              const showRecommender = recommenderName.length > 0 && !isRecommenderSameAsApprover;
              
              const isNot059 = property?.originlguid !== '059';
              const isRecommenderNullOrSame = !recommenderName || isRecommenderSameAsApprover;
              const approverLabel = (isRecommenderNullOrSame && isNot059) ? "By Authority of the Provincial Assessor:" : "Approved By:";

              const colWidth = showRecommender ? '30%' : '45%';

              return (
                <Box sx={{ display: 'flex', mb: 2, alignItems: 'flex-start', justifyContent: 'space-between' }}>
                  <Box sx={{ width: colWidth }}>
                    <Typography sx={{ fontSize: '13px', fontWeight: 'bold', mb: 3 }}>Appraised By:</Typography>
                    <Box sx={{ textAlign: 'center' }}>
                      <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', pb: 0.5 }}>{appraiserName}</Typography>
                      <Typography sx={{ fontSize: '12px' }}>{appraiserTitle}</Typography>
                    </Box>
                  </Box>
                  
                  {showRecommender && (
                    <Box sx={{ width: colWidth }}>
                      <Typography sx={{ fontSize: '13px', fontWeight: 'bold', mb: 3 }}>Recommended By:</Typography>
                      <Box sx={{ textAlign: 'center' }}>
                        <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', pb: 0.5 }}>{recommenderName}</Typography>
                        <Typography sx={{ fontSize: '12px' }}>{recommenderTitle}</Typography>
                      </Box>
                    </Box>
                  )}

                  <Box sx={{ width: colWidth }}>
                    <Typography sx={{ fontSize: '13px', fontWeight: 'bold', mb: 3 }}>{approverLabel}</Typography>
                    <Box sx={{ textAlign: 'center' }}>
                      <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', pb: 0.5 }}>{approverName}</Typography>
                      <Typography sx={{ fontSize: '12px' }}>{approverTitle}</Typography>
                    </Box>
                  </Box>
                </Box>
              );
            })()}

            <Divider sx={{ borderColor: '#000', borderWidth: 1, my: 1 }} />
            
            {/* Previous Info */}
            <Box sx={{ fontSize: '12px' }}>
              <Box sx={{ display: 'flex', mb: 0.5 }}>
                <Typography sx={{ width: '220px', whiteSpace: 'nowrap' }}>This declaration cancels TD No. :</Typography>
                <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '150px' }}>{property.prevtdno || property.prev_tdno || 'NEW'}</Typography>
              </Box>
              <Box sx={{ display: 'flex', mb: 0.5 }}>
                <Typography sx={{ width: '220px', textAlign: 'right', pr: 1, whiteSpace: 'nowrap' }}>Previous PIN :</Typography>
                <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '350px' }}>{property.prev_pin || '-'}</Typography>
              </Box>
              <Box sx={{ display: 'flex', mb: 0.5 }}>
                <Typography sx={{ width: '220px', textAlign: 'right', pr: 1, whiteSpace: 'nowrap' }}>Previous Owner :</Typography>
                <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '350px' }}>{property.prev_owner || '-'}</Typography>
              </Box>
              <Box sx={{ display: 'flex', mb: 0.5 }}>
                <Typography sx={{ width: '220px', textAlign: 'right', pr: 1, whiteSpace: 'nowrap' }}>Previous Administrator :</Typography>
                <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '350px' }}>{property.prev_administrator || '-'}</Typography>
              </Box>
              <Box sx={{ display: 'flex', mb: 1, flexWrap: 'wrap', gap: 2 }}>
                <Box sx={{ display: 'flex' }}>
                  <Typography sx={{ mr: 1 }}>Previous Area (sqm) :</Typography>
                  <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '100px', textAlign: 'center' }}>{property.prev_area_sqm || property.prev_area_hectare || '-'}</Typography>
                </Box>
                <Box sx={{ display: 'flex' }}>
                  <Typography sx={{ mr: 1 }}>Previous M.V. Php :</Typography>
                  <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '150px', textAlign: 'center' }}>{formatCurrency(property.prev_market_value)}</Typography>
                </Box>
                <Box sx={{ display: 'flex' }}>
                  <Typography sx={{ mr: 1 }}>Previous A.V. Php :</Typography>
                  <Typography sx={{ fontWeight: 'bold', borderBottom: '1px solid #000', minWidth: '150px', textAlign: 'center' }}>{formatCurrency(property.prev_assessed_value)}</Typography>
                </Box>
              </Box>
            </Box>

            <Box sx={{ border: '1px solid #000', p: 1, mb: 1, minHeight: '80px', fontSize: '12px' }}>
              <Typography sx={{ display: 'inline', mr: 1, fontWeight: 'bold' }}>MEMORANDA:</Typography>
              <Typography sx={{ display: 'inline' }}>{property.memoranda || '—'}</Typography>
            </Box>

            <Typography sx={{ fontSize: '10px', mb: 2 }}>
              <strong>Note:</strong> This declaration is for real property taxation purposes only and the valuation indicated herein are based on the schedule of unit market values prepared for the purpose and duly enacted into an ordinance... It does not and cannot by itself alone confer any ownership or legal title to the property.
            </Typography>

            {(property.state === 'CANCELLED' || property.rpu_state === 'CANCELLED') && (
              <Box sx={{
                position: 'absolute',
                top: '50%',
                left: '50%',
                transform: 'translate(-50%, -50%)',
                textAlign: 'center',
                color: 'red',
                zIndex: 10,
                pointerEvents: 'none',
                border: '3px solid red',
                p: 2,
                backgroundColor: 'rgba(255,255,255,0.85)'
              }}>
                <Typography sx={{ fontSize: '80px', fontWeight: 'bold', letterSpacing: '10px', lineHeight: 1, mb: 1, opacity: 0.9 }}>
                  CANCELLED
                </Typography>
                <Typography sx={{ fontSize: '18px', fontWeight: 'bold' }}>
                  Cancelled By TD/ARP No. {property.cancelled_by_tdnos || '—'}. 
                  PIN No. {property.cancelled_by_pin || '—'}. 
                  Effective Year {property.cancelled_year || '—'}. 
                  Date {property.cancel_date || '—'}.
                </Typography>
              </Box>
            )}
          </Box>
        )}
      </DialogContent>
      <DialogActions sx={{ p: 2, bgcolor: '#f1f5f9' }}>
        <Button onClick={onClose} variant="contained" color="primary">Close</Button>
      </DialogActions>
    </Dialog>
  );
};

export default TaxDeclarationPreviewModal;
