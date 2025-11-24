import React, { useState } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Avatar,
  Grid,
  Divider,
  Chip,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  useTheme
} from '@mui/material';
import {
  Person as PersonIcon,
  Email as EmailIcon,
  Badge as BadgeIcon,
  Security as SecurityIcon,
  Edit as EditIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { animations } from '../../theme/theme';
import EditProfile from './EditProfile';

const Profile = () => {
  const { user } = useAuth();
  const theme = useTheme();
  const [isEditing, setIsEditing] = useState(false);

  const formatRole = (role) => {
    if (!role || typeof role !== 'string') return '';
    const normalized = role.trim().toLowerCase();
    if (normalized === 'assessor') return 'Municipal Assessor';
    if (normalized === 'municipal assessor') return 'Municipal Assessor';
    if (normalized === 'administrator') return 'Administrator';
    if (normalized === 'admin') return 'Administrator';
    if (normalized === 'superadmin') return 'Super Administrator';
    return role
      .split(/\s+/)
      .map(part => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ');
  };

  const getRoleColor = (role) => {
    if (!role) return 'default';
    const normalized = role.trim().toLowerCase();
    if (normalized === 'superadmin') return 'error';
    if (normalized === 'admin' || normalized === 'administrator') return 'warning';
    if (normalized === 'assessor' || normalized === 'municipal assessor') return 'primary';
    return 'default';
  };

  const handleEdit = () => {
    setIsEditing(true);
  };

  const handleCancel = () => {
    setIsEditing(false);
  };

  const handleSave = () => {
    setIsEditing(false);
  };

  return (
    <Box>
      <motion.div
        initial="initial"
        animate="animate"
        variants={animations.fadeIn}
      >
        <Grid container spacing={3}>
          {/* Profile Header Card */}
          <Grid item xs={12}>
            <Card>
              <CardContent sx={{ p: 3 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 3 }}>
                  <Avatar
                    src={user?.avatar_url || undefined}
                    sx={{
                      width: 80,
                      height: 80,
                      bgcolor: 'primary.main',
                      fontSize: '2rem'
                    }}
                  >
                    {!user?.avatar_url && (user?.full_name?.charAt(0) || user?.username?.charAt(0) || 'U')}
                  </Avatar>
                  <Box sx={{ flex: 1 }}>
                    <Typography variant="h5" fontWeight={600} gutterBottom>
                      {user?.full_name || user?.username || 'User'}
                    </Typography>
                    <Chip
                      label={formatRole(user?.role)}
                      color={getRoleColor(user?.role)}
                      size="small"
                      sx={{ mt: 1 }}
                    />
                  </Box>
                </Box>
              </CardContent>
            </Card>
          </Grid>

          {/* Profile Details Card */}
          <Grid item xs={12} md={8}>
            <Card>
              <CardContent sx={{ p: 3 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                  <Typography variant="h6" fontWeight={600}>
                    Profile Information
                  </Typography>
                  <Button
                    variant="outlined"
                    size="small"
                    startIcon={<EditIcon />}
                    onClick={handleEdit}
                  >
                    Edit Profile
                  </Button>
                </Box>
                <Divider sx={{ mb: 3 }} />

                <Grid container spacing={2}>
                  <Grid item xs={12}>
                    <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, py: 1.5 }}>
                      <PersonIcon color="primary" sx={{ mt: 0.5 }} />
                      <Box sx={{ flex: 1 }}>
                        <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                          Full Name
                        </Typography>
                        <Typography variant="body1">
                          {user?.full_name || 'Not provided'}
                        </Typography>
                      </Box>
                    </Box>
                  </Grid>

                  <Grid item xs={12}>
                    <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, py: 1.5 }}>
                      <BadgeIcon color="primary" sx={{ mt: 0.5 }} />
                      <Box sx={{ flex: 1 }}>
                        <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                          Username
                        </Typography>
                        <Typography variant="body1">
                          {user?.username || 'Not provided'}
                        </Typography>
                      </Box>
                    </Box>
                  </Grid>

                  {user?.email && (
                    <Grid item xs={12}>
                      <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, py: 1.5 }}>
                        <EmailIcon color="primary" sx={{ mt: 0.5 }} />
                        <Box sx={{ flex: 1 }}>
                          <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                            Email
                          </Typography>
                          <Typography variant="body1">
                            {user.email}
                          </Typography>
                        </Box>
                      </Box>
                    </Grid>
                  )}

                  <Grid item xs={12}>
                    <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, py: 1.5 }}>
                      <SecurityIcon color="primary" sx={{ mt: 0.5 }} />
                      <Box sx={{ flex: 1 }}>
                        <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                          Role
                        </Typography>
                        <Typography variant="body1">
                          {formatRole(user?.role)}
                        </Typography>
                      </Box>
                    </Box>
                  </Grid>
                </Grid>
              </CardContent>
            </Card>
          </Grid>

          {/* Additional Info Card */}
          <Grid item xs={12} md={4}>
            <Card>
              <CardContent sx={{ p: 3 }}>
                <Typography variant="h6" fontWeight={600} gutterBottom sx={{ mb: 2 }}>
                  Account Details
                </Typography>
                <Divider sx={{ mb: 3 }} />

                <Box sx={{ mb: 2.5 }}>
                  <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                    User ID
                  </Typography>
                  <Typography variant="body2">
                    {user?.id || 'N/A'}
                  </Typography>
                </Box>

                {user?.created_at && (
                  <Box sx={{ mb: 2.5 }}>
                    <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                      Account Created
                    </Typography>
                    <Typography variant="body2">
                      {new Date(user.created_at).toLocaleDateString()}
                    </Typography>
                  </Box>
                )}

                {user?.updated_at && (
                  <Box>
                    <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                      Last Updated
                    </Typography>
                    <Typography variant="body2">
                      {new Date(user.updated_at).toLocaleDateString()}
                    </Typography>
                  </Box>
                )}
              </CardContent>
            </Card>
          </Grid>
        </Grid>
      </motion.div>

      {/* Edit Profile Modal */}
      <Dialog
        open={isEditing}
        onClose={handleCancel}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>
          <Typography variant="h6" fontWeight={600}>
            Edit Profile
          </Typography>
        </DialogTitle>
        <DialogContent>
          <EditProfile onSave={handleSave} onCancel={handleCancel} />
        </DialogContent>
      </Dialog>
    </Box>
  );
};

export default Profile;

