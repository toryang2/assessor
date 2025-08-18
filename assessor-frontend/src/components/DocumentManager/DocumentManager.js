import React, { useState, useEffect } from 'react';
import {
  Box,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Button,
  Typography,
  Card,
  CardContent,
  Grid,
  IconButton,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Alert,
  Chip,
  LinearProgress,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Divider
} from '@mui/material';
import {
  ArrowBack as ArrowBackIcon,
  Upload as UploadIcon,
  Download as DownloadIcon,
  Delete as DeleteIcon,
  Visibility as VisibilityIcon,
  Description as DescriptionIcon,
  Image as ImageIcon,
  PictureAsPdf as PdfIcon,
  InsertDriveFile as FileIcon,
  Folder as FolderIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { format } from 'date-fns';

import { apiService } from '../../utils/api';
import { useAuth } from '../../contexts/AuthContext';

const DocumentManager = () => {
  // For now, we'll use a default property ID or get it from props
  const propertyId = 1; // This should be passed as a prop or selected from a list
  const { isAdmin } = useAuth();
  const [property, setProperty] = useState(null);
  const [documents, setDocuments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [uploadDialog, setUploadDialog] = useState(false);
  const [deleteDialog, setDeleteDialog] = useState(false);
  const [documentToDelete, setDocumentToDelete] = useState(null);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploading, setUploading] = useState(false);

  useEffect(() => {
    if (propertyId) {
      fetchPropertyAndDocuments();
    }
  }, [propertyId]);

  const fetchPropertyAndDocuments = async () => {
    try {
      setLoading(true);
      
      // Fetch property details
      const propertyResponse = await apiService.getProperty(propertyId);
      setProperty(propertyResponse);
      
      // Fetch documents
      const documentsResponse = await apiService.getPropertyDocuments(propertyId);
      setDocuments(documentsResponse.documents || []);
    } catch (err) {
      setError('Failed to fetch property and documents');
      console.error('Error fetching data:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleFileUpload = async (files) => {
    if (!files || files.length === 0) return;

    setUploading(true);
    setUploadProgress(0);

    try {
      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        
        // Create FormData matching backend expectation
        const formData = new FormData();
        formData.append('document', file);
        formData.append('property_id', propertyId);
        formData.append('description', file.name);

        // Upload file with progress tracking
        await apiService.uploadDocument(propertyId, formData, (progressEvent) => {
          const progress = Math.round((progressEvent.loaded * 100) / progressEvent.total);
          setUploadProgress(progress);
        });
      
        // Update progress for multiple files
        const fileProgress = ((i + 1) / files.length) * 100;
        setUploadProgress(fileProgress);
      }

      // Refresh documents list
      fetchPropertyAndDocuments();
      setUploadDialog(false);
      setUploadProgress(0);
    } catch (err) {
      setError('Failed to upload document(s)');
      console.error('Upload error:', err);
    } finally {
      setUploading(false);
      setUploadProgress(0);
    }
  };

  const handleDownload = async (document) => {
    try {
      await apiService.downloadDocument(document.id, document.filename);
    } catch (err) {
      setError('Failed to download document');
    }
  };

  const handleDeleteDocument = (document) => {
    setDocumentToDelete(document);
    setDeleteDialog(true);
  };

  const confirmDelete = async () => {
    try {
      await apiService.deleteDocument(documentToDelete.id);
      setDeleteDialog(false);
      setDocumentToDelete(null);
      fetchPropertyAndDocuments();
    } catch (err) {
      setError('Failed to delete document');
    }
  };

  const getFileCategory = (mimeType) => {
    if (mimeType.startsWith('image/')) return 'image';
    if (mimeType === 'application/pdf') return 'pdf';
    if (mimeType.startsWith('text/')) return 'text';
    if (mimeType.includes('word') || mimeType.includes('excel') || mimeType.includes('powerpoint')) return 'office';
    return 'other';
  };

  const getFileIconByExtension = (ext) => {
    const e = (ext || '').toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(e)) return <ImageIcon />;
    if (e === 'pdf') return <PdfIcon />;
    if (['txt', 'md', 'csv'].includes(e)) return <DescriptionIcon />;
    return <FileIcon />;
  };

  const getFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  const formatFileType = (ext) => {
    return (ext || 'unknown').toUpperCase();
  };

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <Typography>Loading documents...</Typography>
      </Box>
    );
  }

  if (error) {
    return (
      <Box>
        <Alert severity="error" sx={{ mb: 2 }}>
          {error}
        </Alert>
                 <Button
           variant="outlined"
           startIcon={<ArrowBackIcon />}
           onClick={() => window.history.back()}
         >
           Back
         </Button>
      </Box>
    );
  }

  return (
    <Box component={motion.div} initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
      {/* Header */}
      <Box display="flex" alignItems="center" mb={3}>
                 <Button
           variant="outlined"
           startIcon={<ArrowBackIcon />}
           onClick={() => window.history.back()}
           sx={{ mr: 2 }}
         >
           Back
         </Button>
        <Typography variant="h4">
          Document Manager
        </Typography>
      </Box>

      {/* Property Summary */}
      {property && (
        <Card sx={{ mb: 3 }}>
          <CardContent>
            <Typography variant="h6" gutterBottom>
              Property Information
            </Typography>
            <Grid container spacing={2}>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Tax Declaration
                </Typography>
                <Typography variant="body1" fontWeight={600}>
                  {property.tax_declaration_number}
                </Typography>
              </Grid>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Owner
                </Typography>
                <Typography variant="body1">
                  {property.owner_name}
                </Typography>
              </Grid>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Location
                </Typography>
                <Typography variant="body1">
                  {property.location}
                </Typography>
              </Grid>
              <Grid item xs={12} md={3}>
                <Typography variant="body2" color="text.secondary">
                  Documents
                </Typography>
                <Typography variant="body1" fontWeight={600}>
                  {documents.length} file(s)
                </Typography>
              </Grid>
            </Grid>
          </CardContent>
        </Card>
      )}

      {/* Upload Section */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
            <Typography variant="h6">
              Upload Documents
            </Typography>
            <Button
              variant="contained"
              startIcon={<UploadIcon />}
              onClick={() => setUploadDialog(true)}
              disabled={uploading}
            >
              Upload Files
            </Button>
          </Box>
          
          {uploading && (
            <Box sx={{ width: '100%' }}>
              <LinearProgress variant="determinate" value={uploadProgress} />
              <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                Uploading... {Math.round(uploadProgress)}%
              </Typography>
            </Box>
          )}
        </CardContent>
      </Card>

      {/* Documents Table */}
      <Paper sx={{ width: '100%', overflow: 'hidden' }}>
        <TableContainer>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Document</TableCell>
                <TableCell>Type</TableCell>
                <TableCell>Size</TableCell>
                <TableCell>Category</TableCell>
                <TableCell>Uploaded</TableCell>
                <TableCell>Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {documents.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} align="center" sx={{ py: 4 }}>
                    <Box textAlign="center">
                      <FolderIcon sx={{ fontSize: 48, color: 'text.secondary', mb: 1 }} />
                      <Typography variant="h6" color="text.secondary" gutterBottom>
                        No Documents Found
                      </Typography>
                      <Typography variant="body2" color="text.secondary">
                        Upload documents to get started
                      </Typography>
                    </Box>
                  </TableCell>
                </TableRow>
              ) : (
                documents.map((document) => (
                  <TableRow key={document.id} hover>
                    <TableCell>
                      <Box display="flex" alignItems="center">
                        {getFileIconByExtension(document.file_type)}
                        <Box sx={{ ml: 1 }}>
                          <Typography variant="body2" fontWeight={600}>
                            {document.filename}
                          </Typography>
                          {document.description && (
                            <Typography variant="caption" color="text.secondary">
                              {document.description}
                            </Typography>
                          )}
                        </Box>
                      </Box>
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={formatFileType(document.file_type)}
                        size="small"
                        variant="outlined"
                      />
                    </TableCell>
                    <TableCell>
                      {getFileSize(document.file_size)}
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={getFileCategory(document.file_type || '')}
                        size="small"
                        color="primary"
                      />
                    </TableCell>
                    <TableCell>
                      {document.uploaded_at ? format(new Date(document.uploaded_at), 'MMM dd, yyyy') : ''}
                    </TableCell>
                    <TableCell>
                      <Box display="flex" gap={1}>
                        <IconButton
                          size="small"
                          onClick={() => handleDownload(document)}
                          color="primary"
                        >
                          <DownloadIcon />
                        </IconButton>
                        
                        <IconButton
                          size="small"
                          onClick={() => window.open(document.file_url, '_blank')}
                          color="info"
                        >
                          <VisibilityIcon />
                        </IconButton>
                        
                        {isAdmin && (
                          <IconButton
                            size="small"
                            onClick={() => handleDeleteDocument(document)}
                            color="error"
                          >
                            <DeleteIcon />
                          </IconButton>
                        )}
                      </Box>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>
      </Paper>

      {/* Upload Dialog */}
      <Dialog
        open={uploadDialog}
        onClose={() => setUploadDialog(false)}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle>Upload Documents</DialogTitle>
        <DialogContent>
          <Box sx={{ mt: 2 }}>
            <input
              accept="*/*"
              style={{ display: 'none' }}
              id="file-upload"
              multiple
              type="file"
              onChange={(e) => handleFileUpload(e.target.files)}
            />
            <label htmlFor="file-upload">
              <Button
                variant="outlined"
                component="span"
                startIcon={<UploadIcon />}
                fullWidth
                sx={{ py: 3, borderStyle: 'dashed' }}
              >
                Click to select files or drag and drop
              </Button>
            </label>
            
            <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
              Supported formats: Images, PDFs, Documents, Spreadsheets, and other common file types.
              Maximum file size: 10MB per file.
            </Typography>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setUploadDialog(false)}>Cancel</Button>
        </DialogActions>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialog} onClose={() => setDeleteDialog(false)}>
        <DialogTitle>Confirm Document Deletion</DialogTitle>
        <DialogContent>
          <Typography>
            Are you sure you want to delete "{documentToDelete?.filename}"?
            This action cannot be undone.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteDialog(false)}>Cancel</Button>
          <Button onClick={confirmDelete} color="error" variant="contained">
            Delete
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default DocumentManager;



