import React from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  Box,
  Divider,
  Chip,
  IconButton
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import CloseIcon from '@mui/icons-material/Close';
import changelogData from '../../data/changelog.json';
import { motion } from 'framer-motion';

const ChangelogModal = ({ open, onClose }) => {
  return (
    <Dialog 
      open={open} 
      onClose={onClose} 
      maxWidth="sm" 
      fullWidth
      PaperProps={{
        component: motion.div,
        initial: { opacity: 0, y: 20 },
        animate: { opacity: 1, y: 0 },
        transition: { duration: 0.3 },
        sx: { 
          borderRadius: 4, 
          overflow: 'hidden',
          boxShadow: '0px 10px 40px rgba(0,0,0,0.2)' 
        }
      }}
    >
      <DialogTitle sx={{ 
        m: 0, 
        p: 2.5,
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center'
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <AutoAwesomeIcon sx={{ fontSize: 28, color: 'primary.main' }} />
          <Typography variant="h6" fontWeight="bold">
            What's New in Assessor App
          </Typography>
        </Box>
        <IconButton size="small" onClick={onClose} sx={{ color: 'text.secondary', '&:hover': { color: 'text.primary' } }}>
          <CloseIcon />
        </IconButton>
      </DialogTitle>

      <DialogContent dividers sx={{ p: 0 }}>
        <List sx={{ p: 0 }}>
          {changelogData.map((release, index) => (
            <ListItem 
              key={release.version} 
              sx={{ 
                flexDirection: 'column', 
                alignItems: 'stretch', 
                p: 4, 
                borderBottom: index < changelogData.length - 1 ? 1 : 0, 
                borderColor: 'divider',
                bgcolor: 'background.paper'
              }}
            >
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 2, gap: 2 }}>
                <Typography variant="h6" color="primary" sx={{ fontWeight: 800 }}>
                  v{release.version}
                </Typography>
                <Chip label={release.date} size="small" sx={{ fontWeight: 600, bgcolor: 'primary.50', color: 'primary.700' }} />
              </Box>
              <List disablePadding>
                {release.features.map((feature, idx) => (
                  <ListItem key={idx} alignItems="flex-start" sx={{ px: 0, py: 1 }}>
                    <ListItemIcon sx={{ minWidth: 36, mt: 0.5 }}>
                      <CheckCircleIcon color="success" fontSize="small" />
                    </ListItemIcon>
                    <ListItemText 
                      primary={feature} 
                      primaryTypographyProps={{ variant: 'body2', color: 'text.secondary', fontWeight: 500, lineHeight: 1.6 }}
                    />
                  </ListItem>
                ))}
              </List>
            </ListItem>
          ))}
        </List>
      </DialogContent>
      <DialogActions sx={{ p: 2.5 }}>
        <Button onClick={onClose} variant="contained" size="large" sx={{ borderRadius: 2, px: 4, fontWeight: 'bold', textTransform: 'none' }}>
          Awesome, got it!
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default ChangelogModal;
