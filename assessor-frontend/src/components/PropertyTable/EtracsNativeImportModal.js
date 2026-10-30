import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
  CircularProgress,
  IconButton,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  LinearProgress,
  Chip,
  Tabs,
  Tab,
  TextField,
  InputAdornment,
  TablePagination,
  Alert,
  Tooltip,
  Paper,
  Grid,
  Card,
  CardContent,
  Collapse,
  Checkbox,
  MenuItem,
  FormControl,
  InputLabel,
  Select,
  Divider,
} from '@mui/material';
import {
  Close as CloseIcon,
  Refresh as RefreshIcon,
  Download as DownloadIcon,
  Search as SearchIcon,
  CheckCircle as CheckCircleIcon,
  Warning as WarningIcon,
  Error as ErrorIcon,
  Info as InfoIcon,
  KeyboardArrowDown as KeyboardArrowDownIcon,
  KeyboardArrowUp as KeyboardArrowUpIcon,
  PlayArrow as PlayArrowIcon,
  DoneAll as DoneAllIcon,
  FactCheck as FactCheckIcon,
  CloudUpload as CloudUploadIcon,
  CompareArrows as CompareArrowsIcon,
  VerifiedUser as VerifiedUserIcon,
  ClearAll as ClearAllIcon,
  FilterAlt as FilterAltIcon,
} from '@mui/icons-material';
import { etracsNativeImportApi } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';

function TabPanel(props) {
  const { children, value, index, sx, innerSx, ...other } = props;
  return (
    <Box
      role="tabpanel"
      hidden={value !== index}
      id={`import-tabpanel-${index}`}
      aria-labelledby={`import-tab-${index}`}
      {...other}
      sx={{
        height: '100%',
        display: value === index ? 'flex' : 'none',
        flexDirection: 'column',
        minHeight: 0,
        overflow: 'hidden',
        ...sx,
      }}
    >
      {value === index && (
        <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', ...innerSx }}>
          {children}
        </Box>
      )}
    </Box>
  );
}

export default function EtracsNativeImportModal({ open, onClose }) {
  const { isAdmin, isSuperAdmin } = useAuth();
  const [activeTab, setActiveTab] = useState(0);

  // Overview State
  const [overview, setOverview] = useState(null);
  const [loadingOverview, setLoadingOverview] = useState(false);

  // Crosswalk Audit State
  const [locations, setLocations] = useState([]);
  const [loadingAudit, setLoadingAudit] = useState(false);
  const [approvingBarangayId, setApprovingBarangayId] = useState(null);

  // Preview State
  const [previewItems, setPreviewItems] = useState([]);
  const [totalCandidates, setTotalCandidates] = useState(0);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(25);
  const [expandedRows, setExpandedRows] = useState({});

  // Preview Filters
  const [filterTdno, setFilterTdno] = useState('');
  const [filterState, setFilterState] = useState('');
  const [filterEligibility, setFilterEligibility] = useState('ALL_CANDIDATES');
  const [filterWarningType, setFilterWarningType] = useState('');
  const [filterBarangay, setFilterBarangay] = useState('');
  const [filterGenClass, setFilterGenClass] = useState('');
  const [filterPropType, setFilterPropType] = useState('');

  // Selection for Batch Import
  const [selectedIds, setSelectedIds] = useState([]);

  // Import Execution & Progress State
  const [importing, setImporting] = useState(false);
  const [importBatchId, setImportBatchId] = useState(null);
  const [importProgress, setImportProgress] = useState({ current: 0, total: 0 });
  const [importResults, setImportResults] = useState([]);
  const [confirmDialogOpen, setConfirmDialogOpen] = useState(false);
  const [actionError, setActionError] = useState(null);

  // Clear all preview filters
  const handleClearFilters = () => {
    setFilterTdno('');
    setFilterState('');
    setFilterEligibility('ALL_CANDIDATES');
    setFilterWarningType('');
    setFilterBarangay('');
    setFilterGenClass('');
    setFilterPropType('');
    setPage(0);
  };

  // Fetch Overview Data
  const fetchOverview = useCallback(async () => {
    try {
      setLoadingOverview(true);
      const data = await etracsNativeImportApi.getOverview();
      setOverview(data);
    } catch (err) {
      console.error('Failed to load overview:', err);
    } finally {
      setLoadingOverview(false);
    }
  }, []);

  // Fetch Location Audit
  const fetchLocationAudit = useCallback(async () => {
    try {
      setLoadingAudit(true);
      const data = await etracsNativeImportApi.getLocationAudit();
      setLocations(data.locations || []);
    } catch (err) {
      console.error('Failed to load location audit:', err);
    } finally {
      setLoadingAudit(false);
    }
  }, []);

  // Fetch Preview
  const fetchPreview = useCallback(async () => {
    try {
      setLoadingPreview(true);
      const params = {
        page: page + 1,
        per_page: rowsPerPage,
        tdno: filterTdno,
        state: filterState,
        eligibility_status: filterEligibility,
        warning_type: filterWarningType,
        barangay: filterBarangay,
        gen_class: filterGenClass,
        property_type: filterPropType,
      };
      const res = await etracsNativeImportApi.getPreview(params);
      setPreviewItems(res.items || []);
      setTotalCandidates(res.total || 0);
    } catch (err) {
      console.error('Failed to load preview:', err);
    } finally {
      setLoadingPreview(false);
    }
  }, [page, rowsPerPage, filterTdno, filterState, filterEligibility, filterWarningType, filterBarangay, filterGenClass, filterPropType]);

  // Initial load
  useEffect(() => {
    if (open) {
      fetchOverview();
      fetchLocationAudit();
      fetchPreview();
    }
  }, [open, fetchOverview, fetchLocationAudit, fetchPreview]);

  // Approve Crosswalk Discrepancy
  const handleApproveCrosswalk = async (source_barangay_objid, native_location_id, name) => {
    try {
      setApprovingBarangayId(source_barangay_objid);
      await etracsNativeImportApi.approveCrosswalk({
        source_barangay_objid,
        native_location_id,
        notes: `Administrator verified mapping for ${name}`,
      });
      await fetchLocationAudit();
      await fetchOverview();
      await fetchPreview();
    } catch (err) {
      alert(`Approval failed: ${err.message || 'Error occurred'}`);
    } finally {
      setApprovingBarangayId(null);
    }
  };

  // Toggle Row Expansion
  const toggleRow = (id) => {
    setExpandedRows((prev) => ({
      ...prev,
      [id]: !prev[id],
    }));
  };

  // Selection handling
  const handleSelectAllOnPage = (e) => {
    if (e.target.checked) {
      const readyIds = previewItems
        .filter((item) => item.eligibility_status === 'READY' || item.eligibility_status === 'READY_WITH_WARNINGS')
        .map((item) => item.source.faas_id);
      setSelectedIds(Array.from(new Set([...selectedIds, ...readyIds])));
    } else {
      const pageIds = new Set(previewItems.map((item) => item.source.faas_id));
      setSelectedIds(selectedIds.filter((id) => !pageIds.has(id)));
    }
  };

  const handleToggleSelect = (id) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    );
  };

  // Export Current Preview Records to CSV (with Source & Target fields)
  const handleExportPreviewCsv = () => {
    if (!previewItems.length) return;
    const headers = [
      'Source FAAS ID',
      'Source TD Number',
      'Source State',
      'Source Owner Name',
      'Source Owner Address',
      'Source Administrator Name',
      'Source Beneficiary Name',
      'Source Title Number',
      'Source Effectivity Year',
      'Source Taxable Flag',
      'Source Exemption Code',
      'Source Exemption Name',
      'Source Cadastral Lot No',
      'Source Survey No',
      'Source Barangay',
      'Source General Class',
      'Source Property Type',
      'Source Area Hectare',
      'Source Area Sqm',
      'Source Market Value',
      'Source Assessed Value',
      'Source Memoranda',
      'Native Location Name',
      'Native Declarant Name',
      'Native Address',
      'Native Business',
      'Native Title Number',
      'Native Effectivity Date',
      'Native Effectivity Exempt',
      'Native Lot Number',
      'Native Survey Number',
      'Native Kind of Property',
      'Native General Class',
      'Native Area Hectare',
      'Native Area Sqm',
      'Native Assessed Value',
      'Native Memoranda',
      'Native Verifier Name',
      'Native Verifier Title',
      'Native Assessor Name',
      'Native Assessor Suffix',
      'Native Assessor Title',
      'Native Assessor License',
      'Native Revision ID',
      'Revision Year Cycle',
      'Revision Match Rule',
      'Eligibility Status',
      'Warnings',
    ];
    const rows = previewItems.map((item) => [
      `"${item.source.faas_id || ''}"`,
      `"${item.source.tdno || ''}"`,
      `"${item.source.state || ''}"`,
      `"${(item.source.owner_name || '').replace(/"/g, '""')}"`,
      `"${(item.source.owner_address || '').replace(/"/g, '""')}"`,
      `"${(item.source.administrator_name || '').replace(/"/g, '""')}"`,
      `"${(item.source.beneficiary_name || '').replace(/"/g, '""')}"`,
      `"${(item.source.titleno || '').replace(/"/g, '""')}"`,
      `"${item.source.effectivityyear || ''}"`,
      `"${item.source.taxable ?? ''}"`,
      `"${item.source.exemption_code || ''}"`,
      `"${(item.source.exemption_name || '').replace(/"/g, '""')}"`,
      `"${(item.source.cadastrallotno || '').replace(/"/g, '""')}"`,
      `"${(item.source.surveyno || '').replace(/"/g, '""')}"`,
      `"${(item.source.barangay_name || '').replace(/"/g, '""')}"`,
      `"${item.source.classification || ''}"`,
      `"${item.source.rpu_type || ''}"`,
      `"${item.source.total_area_hectare ?? ''}"`,
      `"${item.source.total_area_sqm ?? ''}"`,
      `"${item.source.total_market_value ?? ''}"`,
      `"${item.source.total_assessed_value ?? ''}"`,
      `"${(item.source.memoranda || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.location || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.declarant_last_name || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.address || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.business_name || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.title_number || '').replace(/"/g, '""')}"`,
      `"${item.proposed_native?.effectivity_date || ''}"`,
      `"${item.proposed_native?.effectivity_exempt ?? 0}"`,
      `"${(item.proposed_native?.lot_number || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.survey_number || '').replace(/"/g, '""')}"`,
      `"${item.proposed_native?.kind_of_property || ''}"`,
      `"${item.proposed_native?.gen_class || ''}"`,
      `"${item.proposed_native?.area_hectare ?? ''}"`,
      `"${item.proposed_native?.area_sqm ?? ''}"`,
      `"${item.proposed_native?.assessed_value ?? ''}"`,
      `"${(item.proposed_native?.memoranda || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.verifier_signatory_name || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.verifier_signatory_title || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.municipal_assessor_name || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.municipal_assessor_suffix || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.municipal_assessor_title || '').replace(/"/g, '""')}"`,
      `"${(item.proposed_native?.municipal_assessor_license || '').replace(/"/g, '""')}"`,
      `"${item.proposed_native?.revision_id || ''}"`,
      `"${(item.revision_details?.revision_year || '').replace(/"/g, '""')}"`,
      `"${(item.revision_details?.match_rule || '').replace(/"/g, '""')}"`,
      `"${item.eligibility_status || ''}"`,
      `"${(item.warnings || []).join('; ').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `etracs_preview_records_page_${page + 1}_${Date.now()}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // Export Location Crosswalk CSV
  const handleExportLocationCsv = () => {
    if (!locations.length) return;
    const headers = [
      'Source Barangay ID',
      'Source Barangay Name',
      'Source Full Name',
      'Source PIN',
      'PIN Suffix',
      'Native Location ID',
      'Native Location Code',
      'Native Location Name',
      'Native Location PIN',
      'Native Status',
      'Audit Status',
      'Can Import',
      'Is Approved',
      'Affected Candidate Count',
      'Explanation',
    ];
    const rows = locations.map((loc) => [
      `"${loc.source_barangay_objid || ''}"`,
      `"${loc.source_barangay_name || ''}"`,
      `"${loc.source_barangay_fullname || ''}"`,
      `"${loc.source_barangay_pin || ''}"`,
      `"${loc.source_pin_suffix || ''}"`,
      `"${loc.native_location_id || ''}"`,
      `"${loc.native_location_code || ''}"`,
      `"${loc.native_location_name || ''}"`,
      `"${loc.native_location_pin || ''}"`,
      `"${loc.native_is_active ? 'Active' : 'Inactive'}"`,
      `"${loc.match_status || ''}"`,
      `"${loc.can_import ? 'YES' : 'NO'}"`,
      `"${loc.is_approved ? 'YES' : 'NO'}"`,
      `"${loc.affected_candidates || 0}"`,
      `"${(loc.explanation || '').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `etracs_location_crosswalk_audit_${Date.now()}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // Export Import Results CSV
  const handleExportResultsCsv = () => {
    if (!importResults.length) return;
    const headers = [
      'Source FAAS ID',
      'Source RPU ID',
      'Source TD Number',
      'Source Lifecycle State',
      'Source Owner Name',
      'Source Owner Address',
      'Source Administrator Name',
      'Source Beneficiary Name',
      'Source Title Number',
      'Source Effectivity Year',
      'Source Taxable Flag',
      'Source Cadastral Lot No',
      'Source Survey No',
      'Source Memoranda',
      'Native Property ID',
      'Native Location Name',
      'Native Address',
      'Native Business',
      'Native Title Number',
      'Native Effectivity Date',
      'Native Effectivity Exempt',
      'Native Lot Number',
      'Native Survey Number',
      'Native Memoranda',
      'Native Verifier Name',
      'Native Verifier Title',
      'Native Assessor Name',
      'Native Assessor Suffix',
      'Native Assessor Title',
      'Native Assessor License',
      'Native Revision ID',
      'Import Status',
      'State Written',
      'Provenance Written',
      'Sync Queued',
      'Batch ID',
      'Timestamp',
      'Error Detail',
      'Warnings',
    ];
    const rows = importResults.map((res) => [
      `"${res.source_faas_objid || ''}"`,
      `"${res.source_rpu_objid || ''}"`,
      `"${res.source_tdno || ''}"`,
      `"${res.source_state || ''}"`,
      `"${(res.source_owner_name || '').replace(/"/g, '""')}"`,
      `"${(res.source_owner_address || '').replace(/"/g, '""')}"`,
      `"${(res.source_administrator_name || '').replace(/"/g, '""')}"`,
      `"${(res.source_beneficiary_name || '').replace(/"/g, '""')}"`,
      `"${(res.source_titleno || '').replace(/"/g, '""')}"`,
      `"${res.source_effectivityyear || ''}"`,
      `"${res.source_taxable ?? ''}"`,
      `"${(res.source_cadastrallotno || '').replace(/"/g, '""')}"`,
      `"${(res.source_surveyno || '').replace(/"/g, '""')}"`,
      `"${(res.source_memoranda || '').replace(/"/g, '""')}"`,
      `"${res.native_property_id || ''}"`,
      `"${res.native_location_name || ''}"`,
      `"${(res.target_address || '').replace(/"/g, '""')}"`,
      `"${(res.target_business || '').replace(/"/g, '""')}"`,
      `"${(res.target_title_number || '').replace(/"/g, '""')}"`,
      `"${res.target_effectivity_date || ''}"`,
      `"${res.target_effectivity_exempt ?? 0}"`,
      `"${(res.target_lot_number || '').replace(/"/g, '""')}"`,
      `"${(res.target_survey_number || '').replace(/"/g, '""')}"`,
      `"${(res.target_memoranda || '').replace(/"/g, '""')}"`,
      `"${(res.target_verifier_name || '').replace(/"/g, '""')}"`,
      `"${(res.target_verifier_title || '').replace(/"/g, '""')}"`,
      `"${(res.target_assessor_name || '').replace(/"/g, '""')}"`,
      `"${(res.target_assessor_suffix || '').replace(/"/g, '""')}"`,
      `"${(res.target_assessor_title || '').replace(/"/g, '""')}"`,
      `"${(res.target_assessor_license || '').replace(/"/g, '""')}"`,
      `"${res.target_revision_id || ''}"`,
      `"${res.status || ''}"`,
      `"${res.state_written ? 'YES' : 'NO'}"`,
      `"${res.provenance_written ? 'YES' : 'NO'}"`,
      `"${res.sync_queued ? 'YES' : 'NO'}"`,
      `"${res.import_batch_id || ''}"`,
      `"${res.timestamp || ''}"`,
      `"${(res.error || res.sync_error || '').replace(/"/g, '""')}"`,
      `"${(res.warnings || []).join('; ').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `etracs_import_results_${Date.now()}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // Execute Batch Import
  const handleExecuteImport = async () => {
    setConfirmDialogOpen(false);
    if (!selectedIds.length) return;

    setImporting(true);
    setActionError(null);
    setActiveTab(4); // Switch to Results tab
    setImportProgress({ current: 0, total: selectedIds.length });

    const batchChunkSize = 50; // Bounded safe batch size
    const allResults = [];
    const currentBatchId = (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : `batch_${Date.now()}`;
    setImportBatchId(currentBatchId);

    try {
      for (let i = 0; i < selectedIds.length; i += batchChunkSize) {
        const chunk = selectedIds.slice(i, i + batchChunkSize);
        const response = await etracsNativeImportApi.importBatch({
          source_faas_ids: chunk,
          batch_id: currentBatchId,
        });

        if (response && response.results) {
          allResults.push(...response.results);
          setImportResults([...allResults]);
          setImportProgress({ current: allResults.length, total: selectedIds.length });
        }
      }

      // Refresh overview, preview, and selection
      setSelectedIds([]);
      await fetchOverview();
      await fetchPreview();
    } catch (err) {
      console.error('Import execution error:', err);
      setActionError(err.message || 'An error occurred during import execution.');
    } finally {
      setImporting(false);
    }
  };

  // Helper status chip
  const renderStatusChip = (status) => {
    switch (status) {
      case 'READY':
        return <Chip size="small" label="READY" color="success" variant="outlined" />;
      case 'READY_WITH_WARNINGS':
        return <Chip size="small" label="READY WITH WARNINGS" color="warning" variant="outlined" />;
      case 'BLOCKED_LOCATION_MISMATCH':
      case 'BLOCKED_LOCATION_DISCREPANCY':
        return <Chip size="small" label="LOCATION MISMATCH" color="error" variant="filled" />;
      case 'BLOCKED':
        return <Chip size="small" label="BLOCKED" color="error" variant="filled" />;
      case 'SKIPPED_NO_RPU':
        return <Chip size="small" label="MISSING RPU" color="default" variant="outlined" />;
      case 'SKIPPED_ALREADY_NATIVE':
        return <Chip size="small" label="ALREADY NATIVE" color="info" variant="outlined" />;
      case 'SKIPPED_ALREADY_IMPORTED':
        return <Chip size="small" label="ALREADY IMPORTED" color="secondary" variant="outlined" />;
      case 'IMPORTED':
        return <Chip size="small" label="IMPORTED" color="success" />;
      case 'FAILED':
        return <Chip size="small" label="FAILED" color="error" />;
      default:
        return <Chip size="small" label={status} color="default" />;
    }
  };

  // Helper warning chip
  const renderWarningChip = (code) => {
    switch (code) {
      case 'ZERO_ASSESSED_VALUE':
        return <Chip key={code} size="small" label="Zero AV" color="warning" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'MISSING_ASSESSED_VALUE':
        return <Chip key={code} size="small" label="Missing AV" color="error" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'ZERO_APPLICABLE_AREA':
        return <Chip key={code} size="small" label="Zero Area" color="warning" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'MISSING_APPLICABLE_AREA':
        return <Chip key={code} size="small" label="Missing Area" color="error" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'UNMAPPED_GENERAL_CLASS':
        return <Chip key={code} size="small" label="Unmapped Class" color="default" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'LOCATION_NAME_DISCREPANCY':
        return <Chip key={code} size="small" label="Location Review" color="error" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'NO_MATCHING_REVISION':
        return <Chip key={code} size="small" label="No Revision Match" color="warning" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'MISSING_EXEMPTION_INFO':
        return <Chip key={code} size="small" label="Missing Exemption" color="warning" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'AMBIGUOUS_EXEMPTION_CODE':
        return <Chip key={code} size="small" label="Ambiguous Exemption" color="warning" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'CONFLICTING_EXEMPTION_DATA':
        return <Chip key={code} size="small" label="Exemption Conflict" color="error" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      case 'UNRESOLVED_EXEMPTION_TYPE':
        return <Chip key={code} size="small" label="Unresolved Exemption Type" color="warning" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
      default:
        return <Chip key={code} size="small" label={code} color="default" sx={{ height: 20, fontSize: '0.68rem', mr: 0.5, mb: 0.5 }} />;
    }
  };

  if (!isAdmin && !isSuperAdmin) {
    return null;
  }

  return (
    <>
      <Dialog
        open={open}
        onClose={importing ? undefined : onClose}
        maxWidth="xl"
        fullWidth
        PaperProps={{
          sx: {
            height: '92vh',
            display: 'flex',
            flexDirection: 'column',
            bgcolor: 'background.paper',
          },
        }}
      >
        {/* Modal Header */}
        <DialogTitle
          sx={{
            m: 0,
            p: 2,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            bgcolor: 'primary.main',
            color: 'primary.contrastText',
          }}
        >
          <Box display="flex" alignItems="center" gap={1.5}>
            <CloudUploadIcon fontSize="large" />
            <Box>
              <Typography variant="h6" fontWeight="bold">
                Import from ETRACS Replica
              </Typography>
              <Typography variant="caption" sx={{ opacity: 0.9 }}>
                Audit locations, inspect joined FAAS & RPU records, and migrate into native Assessor properties
              </Typography>
            </Box>
          </Box>
          <IconButton
            aria-label="close"
            onClick={onClose}
            disabled={importing}
            sx={{ color: 'inherit' }}
          >
            <CloseIcon />
          </IconButton>
        </DialogTitle>

        {/* Navigation Tabs */}
        <Box sx={{ borderBottom: 1, borderColor: 'divider', px: 2, bgcolor: 'background.default' }}>
          <Tabs
            value={activeTab}
            onChange={(e, v) => setActiveTab(v)}
            variant="scrollable"
            scrollButtons="auto"
          >
            <Tab icon={<InfoIcon fontSize="small" />} iconPosition="start" label="A. Overview & Counts" />
            <Tab icon={<CompareArrowsIcon fontSize="small" />} iconPosition="start" label="B. Location Crosswalk Audit" />
            <Tab icon={<FactCheckIcon fontSize="small" />} iconPosition="start" label="C. Source & Target Preview" />
            <Tab icon={<VerifiedUserIcon fontSize="small" />} iconPosition="start" label="D. Confirmation & Batching" />
            <Tab icon={<DoneAllIcon fontSize="small" />} iconPosition="start" label="E. Results & Error Report" />
          </Tabs>
        </Box>

        {/* Modal Body */}
        <DialogContent sx={{ p: 0, flex: 1, minHeight: 0, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
          {actionError && (
            <Box sx={{ p: 2, pb: 0 }}>
              <Alert severity="error" onClose={() => setActionError(null)}>
                {actionError}
              </Alert>
            </Box>
          )}

          {/* TAB 0: OVERVIEW & COUNTS */}
          <TabPanel value={activeTab} index={0} sx={{ height: '100%', overflowY: 'auto' }} innerSx={{ p: 2 }}>
            {loadingOverview && !overview ? (
              <Box display="flex" justifyContent="center" p={6}>
                <CircularProgress />
              </Box>
            ) : overview ? (
              <Box>
                <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
                  <Typography variant="subtitle1" fontWeight="bold">
                    Dynamic Candidate Identification Metrics (Live Assessor Local Database)
                  </Typography>
                  <Button
                    size="small"
                    startIcon={<RefreshIcon />}
                    onClick={fetchOverview}
                    disabled={loadingOverview}
                  >
                    Refresh Counts
                  </Button>
                </Box>

                <Grid container spacing={2}>
                  <Grid item xs={12} sm={6} md={3}>
                    <Card variant="outlined" sx={{ bgcolor: 'rgba(25, 118, 210, 0.04)' }}>
                      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                        <Typography variant="caption" color="text.secondary">Total Source Eligible</Typography>
                        <Typography variant="h5" fontWeight="bold" color="primary.main">
                          {overview.total_source_eligible?.toLocaleString()}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          CURRENT / CANCELLED with TD
                        </Typography>
                      </CardContent>
                    </Card>
                  </Grid>

                  <Grid item xs={12} sm={6} md={3}>
                    <Card variant="outlined" sx={{ bgcolor: 'rgba(2, 136, 209, 0.04)' }}>
                      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                        <Typography variant="caption" color="text.secondary">Already Native Properties</Typography>
                        <Typography variant="h5" fontWeight="bold" color="info.main">
                          {overview.already_native?.toLocaleString()}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          Excluded to prevent duplicates
                        </Typography>
                      </CardContent>
                    </Card>
                  </Grid>

                  <Grid item xs={12} sm={6} md={3}>
                    <Card variant="outlined" sx={{ bgcolor: 'rgba(156, 39, 176, 0.04)' }}>
                      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                        <Typography variant="caption" color="text.secondary">Candidates After Native Exclusion</Typography>
                        <Typography variant="h5" fontWeight="bold" color="secondary.main">
                          {overview.total_candidates?.toLocaleString()}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          Including missing RPU
                        </Typography>
                      </CardContent>
                    </Card>
                  </Grid>

                  <Grid item xs={12} sm={6} md={3}>
                    <Card variant="outlined" sx={{ bgcolor: 'rgba(46, 125, 50, 0.04)' }}>
                      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                        <Typography variant="caption" color="text.secondary">Ready to Import</Typography>
                        <Typography variant="h5" fontWeight="bold" color="success.main">
                          {overview.ready_to_import?.toLocaleString()}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          Verified RPU & Approved Location
                        </Typography>
                      </CardContent>
                    </Card>
                  </Grid>

                  {/* Secondary Metrics */}
                  <Grid item xs={12} sm={4}>
                    <Paper variant="outlined" sx={{ p: 2 }}>
                      <Typography variant="subtitle2" fontWeight="bold" gutterBottom>
                        RPU & State Breakdown
                      </Typography>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Candidates with Valid RPU:</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.with_rpu?.toLocaleString()}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Candidates Missing RPU:</Typography>
                        <Typography variant="body2" fontWeight="bold" color="error.main">{overview.missing_rpu}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">CURRENT with RPU:</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.current_with_rpu?.toLocaleString()}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">CANCELLED with RPU:</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.cancelled_with_rpu?.toLocaleString()}</Typography>
                      </Box>
                    </Paper>
                  </Grid>

                  <Grid item xs={12} sm={4}>
                    <Paper variant="outlined" sx={{ p: 2 }}>
                      <Typography variant="subtitle2" fontWeight="bold" gutterBottom>
                        Location Crosswalk Status
                      </Typography>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Barangays with Discrepancies:</Typography>
                        <Typography variant="body2" fontWeight="bold" color={overview.location_discrepancies_barangays > 0 ? 'warning.main' : 'text.primary'}>
                          {overview.location_discrepancies_barangays}
                        </Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Blocked Candidates (Pending Approval):</Typography>
                        <Typography variant="body2" fontWeight="bold" color={overview.blocked_location_candidates > 0 ? 'warning.main' : 'text.primary'}>
                          {overview.blocked_location_candidates?.toLocaleString()}
                        </Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Duplicate Source TDs:</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.duplicate_source_tds}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Already Imported (Provenance):</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.already_imported_provenance}</Typography>
                      </Box>
                    </Paper>
                  </Grid>

                  <Grid item xs={12} sm={4}>
                    <Paper variant="outlined" sx={{ p: 2 }}>
                      <Typography variant="subtitle2" fontWeight="bold" gutterBottom>
                        Mapping Warnings & Diagnostics
                      </Typography>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Unmapped Class (RP&LGU → NULL):</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.unmapped_class_rplgu}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Numeric Zero Area (0.00):</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.zero_area}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Missing Area (NULL):</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.missing_area || 0}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Valid Numeric Zero AV (0.00):</Typography>
                        <Typography variant="body2" fontWeight="bold" color="warning.main">{overview.zero_av}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Genuinely Missing AV (NULL):</Typography>
                        <Typography variant="body2" fontWeight="bold" color={overview.missing_av > 0 ? 'error.main' : 'text.primary'}>{overview.missing_av || 0}</Typography>
                      </Box>
                    </Paper>
                  </Grid>

                  {/* Exemption & Revision Breakdown */}
                  <Grid item xs={12} sm={6}>
                    <Paper variant="outlined" sx={{ p: 2 }}>
                      <Typography variant="subtitle2" fontWeight="bold" gutterBottom color="primary">
                        Exemption Status Mapping (#39)
                      </Typography>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Verified Taxable (taxable = 1 → exempt: 0):</Typography>
                        <Typography variant="body2" fontWeight="bold">{overview.verified_taxable?.toLocaleString() || 0}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Verified Tax-Exempt (taxable = 0 → exempt: 1):</Typography>
                        <Typography variant="body2" fontWeight="bold" color="success.main">{overview.verified_exempt?.toLocaleString() || 0}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Missing Exemption Info (NULL):</Typography>
                        <Typography variant="body2" fontWeight="bold" color={overview.missing_exemption > 0 ? 'warning.main' : 'text.primary'}>{overview.missing_exemption || 0}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Ambiguous Exemption Code:</Typography>
                        <Typography variant="body2" fontWeight="bold" color={overview.ambiguous_exemption > 0 ? 'warning.main' : 'text.primary'}>{overview.ambiguous_exemption || 0}</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Differs From Historical Default (0):</Typography>
                        <Typography variant="body2" fontWeight="bold" color="info.main">{overview.differs_from_default_exempt?.toLocaleString() || 0}</Typography>
                      </Box>
                    </Paper>
                  </Grid>

                  <Grid item xs={12} sm={6}>
                    <Paper variant="outlined" sx={{ p: 2 }}>
                      <Typography variant="subtitle2" fontWeight="bold" gutterBottom color="primary">
                        Revision & Signatory Configuration (#26–#31, #33)
                      </Typography>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Canonical Signatories Source:</Typography>
                        <Chip size="small" label="Assessor Settings API (Active)" color="success" variant="outlined" sx={{ height: 20, fontSize: '0.7rem' }} />
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Revision Year Matching:</Typography>
                        <Typography variant="body2">Source effectivity year → native revision cycle</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Active Native Revision Codes:</Typography>
                        <Typography variant="body2" fontWeight="bold">SP OD. 2002-006R, GR 2018, GR 2022</Typography>
                      </Box>
                      <Box display="flex" justifyContent="space-between" py={0.5}>
                        <Typography variant="body2">Unresolved Revision Cycles:</Typography>
                        <Typography variant="body2" fontWeight="bold" color="warning.main">1 (TD with malformed year 202)</Typography>
                      </Box>
                    </Paper>
                  </Grid>
                </Grid>

                {/* Zero Assessed Value Distribution Summary */}
                {overview.zero_av_breakdown && overview.zero_av_breakdown.length > 0 && (
                  <Paper variant="outlined" sx={{ p: 2, mt: 2, bgcolor: 'rgba(237, 108, 2, 0.04)' }}>
                    <Box display="flex" alignItems="center" gap={1} mb={1}>
                      <WarningIcon color="warning" fontSize="small" />
                      <Typography variant="subtitle2" fontWeight="bold">
                        Zero Assessed Value Candidates Distribution by Source Class & Property Type ({overview.zero_av} total)
                      </Typography>
                    </Box>
                    <Typography variant="caption" color="text.secondary" paragraph sx={{ mb: 1.5 }}>
                      Source records with an explicit numeric zero assessed value (₱0.00). These are preserved as 0.00 in native mappings and eligible with warning unless blocked by location discrepancy.
                    </Typography>
                    <Box display="flex" flexWrap="wrap" gap={1}>
                      {overview.zero_av_breakdown.map((item, idx) => (
                        <Chip
                          key={idx}
                          size="small"
                          variant="outlined"
                          label={`${item.classification} (${item.rpu_type}): ${item.cnt}`}
                          sx={{ bgcolor: 'background.paper', fontWeight: 'medium' }}
                        />
                      ))}
                    </Box>
                  </Paper>
                )}

                <Alert severity="info" sx={{ mt: 2 }}>
                  <Typography variant="body2">
                    <strong>Preflight Rule:</strong> The preview and audit tabs are read-only. Database uniqueness constraints on the provenance sidecar table (<code>wp_assessor_etracs_import_map</code>) and atomic per-record transactions ensure idempotent imports without modifying source replica records.
                  </Typography>
                </Alert>
              </Box>
            ) : null}
          </TabPanel>

          {/* TAB 1: LOCATION CROSSWALK AUDIT */}
          <TabPanel value={activeTab} index={1} sx={{ height: '100%' }} innerSx={{ p: 2, height: '100%', minHeight: 0 }}>
            <Box display="flex" justifyContent="space-between" alignItems="center" mb={1.5} flexShrink={0}>
              <Box>
                <Typography variant="subtitle1" fontWeight="bold">
                  Location Crosswalk Audit & Discrepancy Resolution
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Rule: PIN numeric suffix against native location PIN. Material name discrepancies block imports until explicitly approved.
                </Typography>
              </Box>
              <Box display="flex" gap={1}>
                <Button size="small" variant="outlined" startIcon={<DownloadIcon />} onClick={handleExportLocationCsv}>
                  Export Audit CSV
                </Button>
                <Button size="small" variant="outlined" startIcon={<RefreshIcon />} onClick={fetchLocationAudit} disabled={loadingAudit}>
                  Refresh
                </Button>
              </Box>
            </Box>

            {loadingAudit ? (
              <Box display="flex" justifyContent="center" p={4}><CircularProgress /></Box>
            ) : (
              <TableContainer component={Paper} variant="outlined" sx={{ flex: 1, minHeight: 0, overflow: 'auto' }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell>Source Barangay</TableCell>
                      <TableCell>Source PIN (Suffix)</TableCell>
                      <TableCell>Proposed Native Location</TableCell>
                      <TableCell>Native PIN</TableCell>
                      <TableCell align="center">Candidates</TableCell>
                      <TableCell>Audit Status</TableCell>
                      <TableCell>Explanation</TableCell>
                      <TableCell align="center">Action</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {locations.map((loc) => {
                      const isDiscrepancy = !loc.can_import;
                      return (
                        <TableRow
                          key={loc.source_barangay_objid}
                          sx={{
                            bgcolor: isDiscrepancy ? 'rgba(237, 108, 2, 0.08)' : 'inherit',
                          }}
                        >
                          <TableCell>
                            <Typography variant="body2" fontWeight="bold">{loc.source_barangay_name}</Typography>
                            <Typography variant="caption" color="text.secondary">{loc.source_barangay_fullname || loc.source_barangay_objid}</Typography>
                          </TableCell>
                          <TableCell>
                            <Typography variant="body2">{loc.source_barangay_pin}</Typography>
                            <Chip size="small" label={`Suffix: ${loc.source_pin_suffix}`} sx={{ height: 20, fontSize: '0.7rem' }} />
                          </TableCell>
                          <TableCell>
                            <Typography variant="body2" fontWeight={loc.native_location_name ? 'bold' : 'normal'}>
                              {loc.native_location_name || 'NONE'}
                            </Typography>
                            {loc.native_location_code && (
                              <Typography variant="caption" color="text.secondary">Code: {loc.native_location_code}</Typography>
                            )}
                          </TableCell>
                          <TableCell>{loc.native_location_pin || '—'}</TableCell>
                          <TableCell align="center">
                            <Chip size="small" label={loc.affected_candidates} color={loc.affected_candidates > 0 ? 'primary' : 'default'} variant="outlined" />
                          </TableCell>
                          <TableCell>
                            {loc.match_status === 'MATCH_CONFIRMED' && <Chip size="small" label="CONFIRMED" color="success" />}
                            {loc.match_status === 'MATCH_CONFIRMED_NAME_VARIATION' && <Chip size="small" label="NAME VARIATION" color="success" variant="outlined" />}
                            {loc.match_status === 'MATCH_APPROVED_NAME_MISMATCH' && <Chip size="small" label="APPROVED MISMATCH" color="info" />}
                            {loc.match_status === 'REVIEW_NAME_MISMATCH' && <Chip size="small" label="REVIEW MISMATCH" color="warning" />}
                            {loc.match_status === 'MISSING_NATIVE_LOCATION' && <Chip size="small" label="MISSING NATIVE" color="error" />}
                            {loc.match_status === 'INACTIVE_NATIVE_LOCATION' && <Chip size="small" label="INACTIVE NATIVE" color="error" />}
                          </TableCell>
                          <TableCell sx={{ maxWidth: 260 }}>
                            <Typography variant="caption" color="text.secondary">{loc.explanation}</Typography>
                            {loc.is_approved && (
                              <Typography variant="caption" display="block" color="info.main">
                                Approved at: {loc.approval?.approved_at}
                              </Typography>
                            )}
                          </TableCell>
                          <TableCell align="center">
                            {loc.match_status === 'REVIEW_NAME_MISMATCH' ? (
                              <Button
                                size="small"
                                variant="contained"
                                color="warning"
                                onClick={() => handleApproveCrosswalk(loc.source_barangay_objid, loc.native_location_id, loc.source_barangay_name)}
                                disabled={approvingBarangayId === loc.source_barangay_objid}
                              >
                                {approvingBarangayId === loc.source_barangay_objid ? 'Approving...' : 'Approve Match'}
                              </Button>
                            ) : loc.is_approved ? (
                              <Chip size="small" label="Approved" color="success" variant="outlined" />
                            ) : (
                              <CheckCircleIcon color="success" fontSize="small" />
                            )}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </TabPanel>

          {/* TAB 2: SOURCE & TARGET PREVIEW */}
          <TabPanel value={activeTab} index={2} sx={{ height: '100%' }} innerSx={{ p: 2, height: '100%', minHeight: 0 }}>
            {/* Filter Bar */}
            <Paper variant="outlined" sx={{ p: 1.5, mb: 1.5, bgcolor: 'background.default', flexShrink: 0 }}>
              <Grid container spacing={1.5} alignItems="center">
                <Grid item xs={12} sm={3}>
                  <TextField
                    size="small"
                    fullWidth
                    placeholder="Search TD Number..."
                    value={filterTdno}
                    onChange={(e) => { setFilterTdno(e.target.value); setPage(0); }}
                    InputProps={{
                      startAdornment: (
                        <InputAdornment position="start">
                          <SearchIcon fontSize="small" />
                        </InputAdornment>
                      ),
                    }}
                  />
                </Grid>

                <Grid item xs={6} sm={2.5}>
                  <FormControl size="small" fullWidth>
                    <InputLabel id="filter-eligibility-label">Eligibility Status</InputLabel>
                    <Select
                      labelId="filter-eligibility-label"
                      value={filterEligibility}
                      label="Eligibility Status"
                      onChange={(e) => { setFilterEligibility(e.target.value); setPage(0); }}
                    >
                      <MenuItem value="ALL_CANDIDATES">All Candidates (Not Native)</MenuItem>
                      <MenuItem value="READY">READY</MenuItem>
                      <MenuItem value="READY_WITH_WARNINGS">READY_WITH_WARNINGS</MenuItem>
                      <MenuItem value="BLOCKED_LOCATION_MISMATCH">BLOCKED_LOCATION_MISMATCH</MenuItem>
                      <MenuItem value="SKIPPED_NO_RPU">SKIPPED_NO_RPU</MenuItem>
                      <MenuItem value="SKIPPED_ALREADY_NATIVE">SKIPPED_ALREADY_NATIVE</MenuItem>
                      <MenuItem value="SKIPPED_ALREADY_IMPORTED">SKIPPED_ALREADY_IMPORTED</MenuItem>
                      <MenuItem value="ALL">All Statuses (Including Native)</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>

                <Grid item xs={6} sm={2.5}>
                  <FormControl size="small" fullWidth>
                    <InputLabel id="filter-warning-label">Warning Type</InputLabel>
                    <Select
                      labelId="filter-warning-label"
                      value={filterWarningType}
                      label="Warning Type"
                      onChange={(e) => { setFilterWarningType(e.target.value); setPage(0); }}
                    >
                      <MenuItem value="">Any / No Warning Filter</MenuItem>
                      <MenuItem value="HAS_ANY_WARNING">Has Any Warning</MenuItem>
                      <MenuItem value="ZERO_ASSESSED_VALUE">ZERO_ASSESSED_VALUE (₱0.00)</MenuItem>
                      <MenuItem value="MISSING_ASSESSED_VALUE">MISSING_ASSESSED_VALUE (NULL)</MenuItem>
                      <MenuItem value="ZERO_APPLICABLE_AREA">ZERO_APPLICABLE_AREA (0.00)</MenuItem>
                      <MenuItem value="MISSING_APPLICABLE_AREA">MISSING_APPLICABLE_AREA (NULL)</MenuItem>
                      <MenuItem value="UNMAPPED_GENERAL_CLASS">UNMAPPED_GENERAL_CLASS (RP&LGU)</MenuItem>
                      <MenuItem value="LOCATION_NAME_DISCREPANCY">LOCATION_NAME_DISCREPANCY</MenuItem>
                      <MenuItem value="NO_MATCHING_REVISION">NO_MATCHING_REVISION</MenuItem>
                      <MenuItem value="MISSING_EXEMPTION_INFO">MISSING_EXEMPTION_INFO</MenuItem>
                      <MenuItem value="CONFLICTING_EXEMPTION_DATA">CONFLICTING_EXEMPTION_DATA</MenuItem>
                      <MenuItem value="UNRESOLVED_EXEMPTION_TYPE">UNRESOLVED_EXEMPTION_TYPE</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>

                <Grid item xs={6} sm={2}>
                  <FormControl size="small" fullWidth>
                    <InputLabel id="filter-state-label">Lifecycle State</InputLabel>
                    <Select
                      labelId="filter-state-label"
                      value={filterState}
                      label="Lifecycle State"
                      onChange={(e) => { setFilterState(e.target.value); setPage(0); }}
                    >
                      <MenuItem value="">All States</MenuItem>
                      <MenuItem value="CURRENT">CURRENT</MenuItem>
                      <MenuItem value="CANCELLED">CANCELLED</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>

                <Grid item xs={6} sm={2} display="flex" gap={1} justifyContent="flex-end">
                  <Button
                    size="small"
                    variant="outlined"
                    color="secondary"
                    startIcon={<ClearAllIcon />}
                    onClick={handleClearFilters}
                  >
                    Clear Filters
                  </Button>
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<RefreshIcon />}
                    onClick={fetchPreview}
                    disabled={loadingPreview}
                  >
                    Refresh
                  </Button>
                </Grid>
              </Grid>

              {/* Active Filter Chips & Matching Indicator */}
              <Box display="flex" justifyContent="space-between" alignItems="center" mt={1} pt={1} borderTop={1} borderColor="divider" flexWrap="wrap" gap={1}>
                <Box display="flex" alignItems="center" gap={1} flexWrap="wrap">
                  <Typography variant="caption" color="text.secondary">
                    Active Filters:
                  </Typography>
                  <Chip
                    size="small"
                    label={`Status: ${filterEligibility === 'ALL_CANDIDATES' ? 'All Candidates' : filterEligibility}`}
                    color={filterEligibility !== 'ALL_CANDIDATES' ? 'primary' : 'default'}
                    variant={filterEligibility !== 'ALL_CANDIDATES' ? 'filled' : 'outlined'}
                    sx={{ height: 22, fontSize: '0.72rem' }}
                  />
                  {filterWarningType && (
                    <Chip
                      size="small"
                      label={`Warning: ${filterWarningType}`}
                      color="warning"
                      onDelete={() => { setFilterWarningType(''); setPage(0); }}
                      sx={{ height: 22, fontSize: '0.72rem' }}
                    />
                  )}
                  {filterState && (
                    <Chip
                      size="small"
                      label={`State: ${filterState}`}
                      color="default"
                      onDelete={() => { setFilterState(''); setPage(0); }}
                      sx={{ height: 22, fontSize: '0.72rem' }}
                    />
                  )}
                  {filterTdno && (
                    <Chip
                      size="small"
                      label={`TD: "${filterTdno}"`}
                      color="default"
                      onDelete={() => { setFilterTdno(''); setPage(0); }}
                      sx={{ height: 22, fontSize: '0.72rem' }}
                    />
                  )}
                </Box>
                <Typography variant="caption" color="text.secondary">
                  Overall candidates: <strong>{overview?.total_candidates?.toLocaleString() || '7,841'}</strong> | Matching records: <strong>{totalCandidates.toLocaleString()}</strong> | Page: <strong>{totalCandidates > 0 ? page + 1 : 0} of {Math.max(1, Math.ceil(totalCandidates / rowsPerPage))}</strong>
                </Typography>
              </Box>
            </Paper>

            {/* Selection Toolbar */}
            <Box display="flex" justifyContent="space-between" alignItems="center" mb={1} flexShrink={0} flexWrap="wrap" gap={1}>
              <Typography variant="body2" color="text.secondary">
                Selected for Import: <strong>{selectedIds.length}</strong> records (Matching Filter: {totalCandidates.toLocaleString()})
              </Typography>
              <Box display="flex" gap={1}>
                <Button
                  size="small"
                  variant="outlined"
                  startIcon={<DownloadIcon />}
                  onClick={handleExportPreviewCsv}
                  disabled={previewItems.length === 0}
                >
                  Export Preview CSV (Page {page + 1})
                </Button>
                {selectedIds.length > 0 && (
                  <Button
                    size="small"
                    variant="contained"
                    color="primary"
                    startIcon={<PlayArrowIcon />}
                    onClick={() => setActiveTab(3)}
                  >
                    Proceed to Confirmation ({selectedIds.length})
                  </Button>
                )}
              </Box>
            </Box>

            {/* Preview Table Body with Clean Bounded Scrolling */}
            {loadingPreview ? (
              <Box display="flex" justifyContent="center" alignItems="center" flex={1} p={6}><CircularProgress /></Box>
            ) : previewItems.length === 0 ? (
              <Paper variant="outlined" sx={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', p: 4 }}>
                <Typography variant="subtitle1" color="text.secondary" gutterBottom>
                  No property records match the selected filters.
                </Typography>
                <Typography variant="body2" color="text.secondary" paragraph>
                  Try clearing or adjusting the Eligibility Status or Warning Type filter criteria.
                </Typography>
                <Button variant="outlined" size="small" startIcon={<ClearAllIcon />} onClick={handleClearFilters}>
                  Clear All Filters
                </Button>
              </Paper>
            ) : (
              <TableContainer component={Paper} variant="outlined" sx={{ flex: 1, minHeight: 0, overflow: 'auto' }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell padding="checkbox">
                        <Checkbox
                          size="small"
                          onChange={handleSelectAllOnPage}
                          indeterminate={
                            previewItems.some((i) => selectedIds.includes(i.source.faas_id)) &&
                            !previewItems.every((i) => selectedIds.includes(i.source.faas_id))
                          }
                          checked={
                            previewItems.length > 0 &&
                            previewItems.every((i) => selectedIds.includes(i.source.faas_id))
                          }
                        />
                      </TableCell>
                      <TableCell width={30} />
                      <TableCell>Source TD Number</TableCell>
                      <TableCell>Owner Name</TableCell>
                      <TableCell>Barangay (Source)</TableCell>
                      <TableCell>Resolved Location (Native)</TableCell>
                      <TableCell>Type / Class</TableCell>
                      <TableCell>Area</TableCell>
                      <TableCell>Assessed Value</TableCell>
                      <TableCell>Warnings / Diagnostics</TableCell>
                      <TableCell>State</TableCell>
                      <TableCell>Eligibility Status</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {previewItems.map((item) => {
                      const id = item.source.faas_id;
                      const isExpanded = !!expandedRows[id];
                      const isSelected = selectedIds.includes(id);
                      const isReady = item.eligibility_status === 'READY' || item.eligibility_status === 'READY_WITH_WARNINGS';
                      const rawAV = item.source?.total_assessed_value;
                      const isNumericZeroAV = rawAV !== null && rawAV !== undefined && Number(rawAV) === 0;
                      const isMissingAV = rawAV === null || rawAV === undefined;

                      return (
                        <React.Fragment key={id}>
                          <TableRow hover selected={isSelected}>
                            <TableCell padding="checkbox">
                              <Checkbox
                                size="small"
                                checked={isSelected}
                                disabled={!isReady}
                                onChange={() => handleToggleSelect(id)}
                              />
                            </TableCell>
                            <TableCell>
                              <IconButton size="small" onClick={() => toggleRow(id)}>
                                {isExpanded ? <KeyboardArrowUpIcon /> : <KeyboardArrowDownIcon />}
                              </IconButton>
                            </TableCell>
                            <TableCell>
                              <Typography variant="body2" fontWeight="bold">{item.source.tdno}</Typography>
                              <Typography variant="caption" color="text.secondary">{item.source.faas_id}</Typography>
                            </TableCell>
                            <TableCell sx={{ maxWidth: 180 }}>
                              <Typography variant="body2" noWrap title={item.source.owner_name}>
                                {item.source.owner_name}
                              </Typography>
                            </TableCell>
                            <TableCell>{item.source.barangay_name}</TableCell>
                            <TableCell>
                              <Typography variant="body2" fontWeight="medium">
                                {item.proposed_native?.location || 'UNRESOLVED'}
                              </Typography>
                            </TableCell>
                            <TableCell>
                              <Typography variant="body2">
                                {item.proposed_native?.kind_of_property || item.source.rpu_type}
                              </Typography>
                              <Typography variant="caption" color="text.secondary">
                                {item.proposed_native?.gen_class || item.source.classification || 'None'}
                              </Typography>
                            </TableCell>
                            <TableCell>
                              {item.proposed_native?.area_hectare != null
                                ? `${item.proposed_native.area_hectare} ha`
                                : item.proposed_native?.area_sqm != null
                                ? `${item.proposed_native.area_sqm} sqm`
                                : '—'}
                            </TableCell>
                            <TableCell>
                              {isMissingAV ? (
                                <Tooltip title="Source assessed value is genuinely absent (NULL in source DB)">
                                  <Typography variant="body2" color="error.main" fontStyle="italic">
                                    Missing
                                  </Typography>
                                </Tooltip>
                              ) : isNumericZeroAV ? (
                                <Tooltip title="Source contains valid numeric zero assessed value (₱0.00)">
                                  <Typography variant="body2" fontWeight="medium" color="warning.dark">
                                    ₱0.00
                                  </Typography>
                                </Tooltip>
                              ) : (
                                <Typography variant="body2">
                                  ₱{Number(item.proposed_native?.assessed_value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                </Typography>
                              )}
                            </TableCell>
                            <TableCell sx={{ maxWidth: 160 }}>
                              {item.warning_codes && item.warning_codes.length > 0 ? (
                                <Box display="flex" flexWrap="wrap">
                                  {item.warning_codes.map((code) => renderWarningChip(code))}
                                </Box>
                              ) : (
                                <Typography variant="caption" color="text.secondary">None</Typography>
                              )}
                            </TableCell>
                            <TableCell>
                              <Chip
                                size="small"
                                label={item.source.state}
                                color={item.source.state === 'CURRENT' ? 'success' : 'default'}
                                variant="outlined"
                              />
                            </TableCell>
                            <TableCell>{renderStatusChip(item.eligibility_status)}</TableCell>
                          </TableRow>

                          {/* Side-by-Side Detailed Breakdown */}
                          <TableRow>
                            <TableCell colSpan={12} sx={{ p: 0, borderBottom: isExpanded ? undefined : 'none' }}>
                              <Collapse in={isExpanded} timeout="auto" unmountOnExit>
                                <Box sx={{ p: 2, bgcolor: 'rgba(0, 0, 0, 0.02)' }}>
                                  <Grid container spacing={2}>
                                    {/* Source ETRACS Data Group */}
                                    <Grid item xs={12} md={6}>
                                      <Paper variant="outlined" sx={{ p: 1.5 }}>
                                        <Typography variant="subtitle2" fontWeight="bold" color="primary" gutterBottom>
                                          Original ETRACS Source Record
                                        </Typography>
                                        <Box display="grid" gridTemplateColumns="1fr 1fr" gap={1} sx={{ fontSize: '0.8rem' }}>
                                          <div><strong>FAAS ObjID:</strong> {item.source.faas_id}</div>
                                          <div><strong>RPU ObjID:</strong> {item.source.rpu_id || 'NONE'}</div>
                                          <div><strong>Real Property ID:</strong> {item.source.real_property_id}</div>
                                          <div><strong>Cadastral Lot No:</strong> {item.source.cadastrallotno ? item.source.cadastrallotno : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>Survey No:</strong> {item.source.surveyno ? item.source.surveyno : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>Full PIN:</strong> {item.source.fullpin || '—'}</div>
                                          <div><strong>Title No:</strong> {item.source.titleno ? item.source.titleno : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>Market Value:</strong> ₱{Number(item.source.total_market_value || 0).toLocaleString()}</div>
                                          <div><strong>Source Assessed Value:</strong> {isMissingAV ? 'Missing (NULL)' : `₱${Number(rawAV).toLocaleString(undefined, { minimumFractionDigits: 2 })}`}</div>
                                          <div><strong>Effectivity Year:</strong> {item.source.effectivityyear ? item.source.effectivityyear : <span style={{ color: '#d32f2f', fontStyle: 'italic' }}>Missing (NULL)</span>}</div>
                                          <div><strong>Approval Date:</strong> {item.source.dtapproved || '—'}</div>
                                          <div><strong>Administrator:</strong> {item.source.administrator_name || '—'}</div>
                                          <div><strong>Beneficiary:</strong> {item.source.beneficiary_name || '—'}</div>
                                          <div><strong>Predecessor TD:</strong> {item.source.prevtdno || '—'}</div>
                                        </Box>
                                        <Box mt={1} sx={{ fontSize: '0.8rem' }}>
                                          <strong>Owner Address:</strong> {item.source.owner_address ? item.source.owner_address : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}
                                        </Box>
                                        <Box mt={0.5} sx={{ fontSize: '0.8rem' }}>
                                          <strong>Memoranda:</strong> {item.source.memoranda || '—'}
                                        </Box>
                                      </Paper>
                                    </Grid>

                                    {/* Proposed Native Destination Data Group */}
                                    <Grid item xs={12} md={6}>
                                      <Paper variant="outlined" sx={{ p: 1.5, borderColor: 'primary.light' }}>
                                        <Typography variant="subtitle2" fontWeight="bold" color="success.main" gutterBottom>
                                          Proposed Native Property Fields
                                        </Typography>
                                        <Box display="grid" gridTemplateColumns="1fr 1fr" gap={1} sx={{ fontSize: '0.8rem' }}>
                                          <div><strong>TDN:</strong> {item.proposed_native?.tax_declaration_number}</div>
                                          <div><strong>Prev TDN:</strong> {item.proposed_native?.previous_tax_declaration_number || '—'}</div>
                                          <div><strong>Declarant:</strong> {item.proposed_native?.declarant_last_name}</div>
                                          <div><strong>Title Number:</strong> {item.proposed_native?.title_number ? item.proposed_native.title_number : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>Effectivity Date:</strong> {item.proposed_native?.effectivity_date ? item.proposed_native.effectivity_date : <span style={{ color: '#d32f2f', fontStyle: 'italic' }}>Missing (NULL)</span>}</div>
                                          <div><strong>Assessment Date:</strong> {item.proposed_native?.assessment_date || '—'}</div>
                                          <div><strong>Business Field:</strong> {item.proposed_native?.business_name ? item.proposed_native.business_name : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>Location:</strong> {item.proposed_native?.location}</div>
                                          <div><strong>Kind of Property:</strong> {item.proposed_native?.kind_of_property}</div>
                                          <div><strong>General Class:</strong> {item.proposed_native?.gen_class || 'NULL'}</div>
                                          <div><strong>Area Ha:</strong> {item.proposed_native?.area_hectare ?? 'NULL'}</div>
                                          <div><strong>Area Sqm:</strong> {item.proposed_native?.area_sqm ?? 'NULL'}</div>
                                          <div>
                                            <strong>Assessed Value:</strong> {item.proposed_native?.assessed_value !== null ? `₱${Number(item.proposed_native?.assessed_value).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : 'NULL'}
                                          </div>
                                          <div><strong>Lot Number:</strong> {item.proposed_native?.lot_number ? item.proposed_native.lot_number : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>Survey Number:</strong> {item.proposed_native?.survey_number ? item.proposed_native.survey_number : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}</div>
                                          <div><strong>PIN:</strong> {item.proposed_native?.pin || '—'}</div>
                                        </Box>
                                        <Box mt={1} sx={{ fontSize: '0.8rem' }}>
                                          <strong>Native Owner Address:</strong> {item.proposed_native?.address ? item.proposed_native.address : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}
                                        </Box>
                                        <Box mt={0.5} sx={{ fontSize: '0.8rem' }}>
                                          <strong>Native Memoranda:</strong> {item.proposed_native?.memoranda ? item.proposed_native.memoranda : <span style={{ color: '#888', fontStyle: 'italic' }}>None (empty in source)</span>}
                                        </Box>

                                        {/* Warnings / Blockers Box */}
                                        {item.blocking_reasons && item.blocking_reasons.length > 0 && (
                                          <Alert severity="error" sx={{ mt: 1, py: 0 }}>
                                            <Typography variant="caption" fontWeight="bold">
                                              Blockers: {item.blocking_reasons.join('; ')}
                                            </Typography>
                                          </Alert>
                                        )}
                                        {item.warnings && item.warnings.length > 0 && (
                                          <Alert severity="warning" sx={{ mt: 1, py: 0 }}>
                                            <Typography variant="caption">
                                              Warnings: {item.warnings.join('; ')}
                                            </Typography>
                                          </Alert>
                                        )}
                                      </Paper>
                                    </Grid>

                                    {/* Exemption Mapping Detail Card (#39) */}
                                    <Grid item xs={12} md={4}>
                                      <Paper variant="outlined" sx={{ p: 1.5, height: '100%', bgcolor: 'background.paper' }}>
                                        <Typography variant="subtitle2" fontWeight="bold" color="primary" gutterBottom>
                                          Exemption Mapping (#39)
                                        </Typography>
                                        <Box display="flex" flexDirection="column" gap={0.75} sx={{ fontSize: '0.8rem' }}>
                                          <div>
                                            <strong>Source Taxable Flag:</strong>{' '}
                                            {item.exemption_details?.source_taxable === 1 ? (
                                              <Chip size="small" label="1 (Taxable)" color="default" sx={{ height: 20, fontSize: '0.7rem' }} />
                                            ) : item.exemption_details?.source_taxable === 0 ? (
                                              <Chip size="small" label="0 (Tax-Exempt)" color="success" sx={{ height: 20, fontSize: '0.7rem' }} />
                                            ) : (
                                              <Chip size="small" label="Missing (NULL)" color="warning" sx={{ height: 20, fontSize: '0.7rem' }} />
                                            )}
                                          </div>
                                          <div>
                                            <strong>Exemption Type Code:</strong> {item.exemption_details?.source_exemption_code || 'None (empty in source)'}
                                          </div>
                                          <div>
                                            <strong>Exemption Type Name:</strong> {item.exemption_details?.source_exemption_name || 'None'}
                                          </div>
                                          <div>
                                            <strong>Proposed effectivity_exempt:</strong>{' '}
                                            {item.proposed_native?.effectivity_exempt === 1 ? (
                                              <Chip size="small" label="1 (EXEMPT)" color="success" variant="filled" sx={{ height: 20, fontSize: '0.7rem', fontWeight: 'bold' }} />
                                            ) : (
                                              <Chip size="small" label="0 (TAXABLE)" color="default" variant="outlined" sx={{ height: 20, fontSize: '0.7rem' }} />
                                            )}
                                          </div>
                                          <div>
                                            <strong>Resolution Rule:</strong>{' '}
                                            <span style={{ color: '#555' }}>{item.exemption_details?.explanation || 'Default resolution'}</span>
                                          </div>
                                        </Box>
                                      </Paper>
                                    </Grid>

                                    {/* Revision Year & Revision ID Card (#33) */}
                                    <Grid item xs={12} md={4}>
                                      <Paper variant="outlined" sx={{ p: 1.5, height: '100%', bgcolor: 'background.paper' }}>
                                        <Typography variant="subtitle2" fontWeight="bold" color="primary" gutterBottom>
                                          Revision Resolution (#33)
                                        </Typography>
                                        <Box display="flex" flexDirection="column" gap={0.75} sx={{ fontSize: '0.8rem' }}>
                                          <div>
                                            <strong>Inferred Revision Year:</strong>{' '}
                                            <Typography component="span" fontWeight="bold" sx={{ fontSize: '0.82rem' }}>
                                              {item.revision_details?.inferred_year || 'Unresolved'}
                                            </Typography>
                                          </div>
                                          <div>
                                            <strong>Revision Cycle Code:</strong>{' '}
                                            {item.revision_details?.matched_revision_code ? (
                                              <Chip size="small" label={item.revision_details.matched_revision_code} color="info" sx={{ height: 20, fontSize: '0.7rem' }} />
                                            ) : (
                                              <Chip size="small" label="NO MATCH" color="warning" sx={{ height: 20, fontSize: '0.7rem' }} />
                                            )}
                                          </div>
                                          <div>
                                            <strong>Native revision_id:</strong>{' '}
                                            <code>{item.proposed_native?.revision_id || 'NULL'}</code>
                                          </div>
                                          <div>
                                            <strong>Matching Rule:</strong>{' '}
                                            <span style={{ color: '#555' }}>{item.revision_details?.matching_rule || 'Year range search in wp_assessor_revision_entries'}</span>
                                          </div>
                                        </Box>
                                      </Paper>
                                    </Grid>

                                    {/* Canonical Assessor & Signatory Configuration (#26–#31) */}
                                    <Grid item xs={12} md={4}>
                                      <Paper variant="outlined" sx={{ p: 1.5, height: '100%', bgcolor: 'background.paper' }}>
                                        <Typography variant="subtitle2" fontWeight="bold" color="primary" gutterBottom>
                                          Assessor & Verifier Signatories (#26–#31)
                                        </Typography>
                                        <Box display="flex" flexDirection="column" gap={0.5} sx={{ fontSize: '0.78rem' }}>
                                          <div><strong>Verifier Signatory:</strong> {item.proposed_native?.verifier_signatory_name || <span style={{ color: '#d32f2f', fontStyle: 'italic' }}>Unconfigured</span>}</div>
                                          <div><strong>Verifier Title:</strong> {item.proposed_native?.verifier_signatory_title || '—'}</div>
                                          <div><strong>Municipal Assessor:</strong> {item.proposed_native?.municipal_assessor_name || <span style={{ color: '#d32f2f', fontStyle: 'italic' }}>Unconfigured</span>}</div>
                                          <div><strong>Assessor Suffix:</strong> {item.proposed_native?.municipal_assessor_suffix || '—'}</div>
                                          <div><strong>Assessor Title:</strong> {item.proposed_native?.municipal_assessor_title || '—'}</div>
                                          <div><strong>Assessor License:</strong> {item.proposed_native?.municipal_assessor_license || '—'}</div>
                                        </Box>
                                      </Paper>
                                    </Grid>
                                  </Grid>
                                </Box>
                              </Collapse>
                            </TableCell>
                          </TableRow>
                        </React.Fragment>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableContainer>
            )}

            {/* Pagination Controls outside scrollable rows */}
            <Box flexShrink={0} borderTop={1} borderColor="divider">
              <TablePagination
                component="div"
                count={totalCandidates}
                page={page}
                onPageChange={(e, p) => setPage(p)}
                rowsPerPage={rowsPerPage}
                onRowsPerPageChange={(e) => {
                  setRowsPerPage(parseInt(e.target.value, 10));
                  setPage(0);
                }}
                rowsPerPageOptions={[10, 25, 50, 100]}
              />
            </Box>
          </TabPanel>

          {/* TAB 3: CONFIRMATION & BATCHING */}
          <TabPanel value={activeTab} index={3} sx={{ height: '100%', overflowY: 'auto' }}>
            <Paper variant="outlined" sx={{ p: 3, maxWidth: 800, mx: 'auto', mt: 2 }}>
              <Typography variant="h6" fontWeight="bold" gutterBottom>
                Import Confirmation & Pre-Execution Safety Verification
              </Typography>
              <Typography variant="body2" color="text.secondary" paragraph>
                Please review your selected batch configuration before initiating the migration. The server will independently verify duplicate keys, lifecycle states, and location approvals immediately before insertion.
              </Typography>

              <Divider sx={{ my: 2 }} />

              <Box display="grid" gridTemplateColumns="1fr 1fr" gap={2} mb={3}>
                <Paper variant="outlined" sx={{ p: 2 }}>
                  <Typography variant="caption" color="text.secondary">Selected Candidates to Import</Typography>
                  <Typography variant="h4" fontWeight="bold" color="primary.main">{selectedIds.length}</Typography>
                </Paper>
                <Paper variant="outlined" sx={{ p: 2 }}>
                  <Typography variant="caption" color="text.secondary">Transaction Mode</Typography>
                  <Typography variant="h6" fontWeight="bold">Atomic Per-Record</Typography>
                  <Typography variant="caption" color="text.secondary">Rollback on any step failure</Typography>
                </Paper>
              </Box>

              <Alert severity="warning" sx={{ mb: 3 }}>
                <Typography variant="body2">
                  <strong>Administrator Confirmation Required:</strong> Importing will create new native property records, insert property lifecycle state entries, record provenance in <code>wp_assessor_etracs_import_map</code>, and enqueue local sync operations. ETRACS replica source tables will NOT be modified.
                </Typography>
              </Alert>

              <Box display="flex" justifyContent="flex-end" gap={2}>
                <Button variant="outlined" onClick={() => setActiveTab(2)}>
                  Back to Preview
                </Button>
                <Button
                  variant="contained"
                  color="primary"
                  size="large"
                  disabled={selectedIds.length === 0 || importing}
                  onClick={() => setConfirmDialogOpen(true)}
                  startIcon={<CloudUploadIcon />}
                >
                  Start Batch Import ({selectedIds.length})
                </Button>
              </Box>
            </Paper>
          </TabPanel>

          {/* TAB 4: RESULTS & ERROR REPORT */}
          <TabPanel value={activeTab} index={4} sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
            <Box display="flex" justifyContent="space-between" alignItems="center" mb={1.5} sx={{ flexShrink: 0 }}>
              <Box>
                <Typography variant="subtitle1" fontWeight="bold">
                  Import Batch Execution Results
                </Typography>
                {importBatchId && (
                  <Typography variant="caption" color="text.secondary">
                    Batch Identifier: <code>{importBatchId}</code>
                  </Typography>
                )}
              </Box>
              <Box display="flex" gap={1}>
                <Button
                  size="small"
                  variant="outlined"
                  startIcon={<DownloadIcon />}
                  onClick={handleExportResultsCsv}
                  disabled={importResults.length === 0}
                >
                  Export Results CSV
                </Button>
              </Box>
            </Box>

            {importing && (
              <Box mb={2} sx={{ flexShrink: 0 }}>
                <Box display="flex" justifyContent="space-between" mb={0.5}>
                  <Typography variant="body2">Importing batch in progress...</Typography>
                  <Typography variant="body2" fontWeight="bold">
                    {importProgress.current} / {importProgress.total} records
                  </Typography>
                </Box>
                <LinearProgress
                  variant="determinate"
                  value={importProgress.total > 0 ? (importProgress.current / importProgress.total) * 100 : 0}
                />
              </Box>
            )}

            {importResults.length === 0 && !importing ? (
              <Box textAlign="center" p={6}>
                <Typography variant="body1" color="text.secondary">
                  No import batch has been executed yet in this session.
                </Typography>
                <Button variant="outlined" sx={{ mt: 2 }} onClick={() => setActiveTab(2)}>
                  Go to Preview to Select Records
                </Button>
              </Box>
            ) : (
              <TableContainer component={Paper} variant="outlined" sx={{ flex: 1, minHeight: 0, overflow: 'auto' }}>
                <Table size="small" stickyHeader>
                  <TableHead>
                    <TableRow>
                      <TableCell>Source TD Number</TableCell>
                      <TableCell>Source FAAS ID</TableCell>
                      <TableCell>Native Property ID</TableCell>
                      <TableCell>Native Location</TableCell>
                      <TableCell>Status</TableCell>
                      <TableCell align="center">State Written</TableCell>
                      <TableCell align="center">Provenance</TableCell>
                      <TableCell align="center">Sync Queued</TableCell>
                      <TableCell>Details / Warnings</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {importResults.map((res, idx) => (
                      <TableRow key={idx}>
                        <TableCell>
                          <Typography variant="body2" fontWeight="bold">{res.source_tdno || '—'}</Typography>
                        </TableCell>
                        <TableCell>{res.source_faas_objid}</TableCell>
                        <TableCell>
                          <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>
                            {res.native_property_id || '—'}
                          </Typography>
                        </TableCell>
                        <TableCell>{res.native_location_name || '—'}</TableCell>
                        <TableCell>{renderStatusChip(res.status)}</TableCell>
                        <TableCell align="center">
                          {res.state_written ? <CheckCircleIcon fontSize="small" color="success" /> : '—'}
                        </TableCell>
                        <TableCell align="center">
                          {res.provenance_written ? <CheckCircleIcon fontSize="small" color="success" /> : '—'}
                        </TableCell>
                        <TableCell align="center">
                          {res.sync_queued ? <CheckCircleIcon fontSize="small" color="success" /> : '—'}
                        </TableCell>
                        <TableCell>
                          {res.error && (
                            <Typography variant="caption" color="error.main" display="block">
                              Error: {res.error}
                            </Typography>
                          )}
                          {res.sync_error && (
                            <Typography variant="caption" color="warning.main" display="block">
                              Sync Queue Note: {res.sync_error}
                            </Typography>
                          )}
                          {res.warnings && res.warnings.length > 0 && (
                            <Typography variant="caption" color="text.secondary" display="block">
                              Warnings: {res.warnings.join('; ')}
                            </Typography>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </TabPanel>
        </DialogContent>

        {/* Modal Footer */}
        <DialogActions sx={{ p: 2, borderTop: 1, borderColor: 'divider' }}>
          <Button onClick={onClose} disabled={importing}>
            Close
          </Button>
        </DialogActions>
      </Dialog>

      {/* Confirmation Dialog */}
      <Dialog
        open={confirmDialogOpen}
        onClose={() => setConfirmDialogOpen(false)}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle sx={{ fontWeight: 'bold' }}>
          Confirm ETRACS Import Execution
        </DialogTitle>
        <DialogContent>
          <Typography variant="body1" paragraph>
            You are about to import <strong>{selectedIds.length}</strong> selected property record(s) into the native Assessor database.
          </Typography>
          <Typography variant="body2" color="text.secondary">
            This action will generate permanent native UUID v7 identifiers, assign lifecycle states, write provenance tracking, and enqueue the properties for local synchronization.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ p: 2 }}>
          <Button onClick={() => setConfirmDialogOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="contained"
            color="primary"
            onClick={handleExecuteImport}
            startIcon={<PlayArrowIcon />}
          >
            Confirm & Import Records
          </Button>
        </DialogActions>
      </Dialog>
    </>
  );
}
