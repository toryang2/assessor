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
  Edit as EditIcon,
  AdminPanelSettings as AdminIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { useAuth } from '../../contexts/AuthContext';
import { animations } from '../../theme/theme';
import { keyframes } from '@mui/system';
import EditProfile from './EditProfile';
import { formatAppDate } from '../../utils/dateTime';

const glow = keyframes`
  0% { filter: drop-shadow(0 0 0px rgba(156, 39, 176, 0.0)); }
  50% { filter: drop-shadow(0 0 10px rgba(156, 39, 176, 0.75)) drop-shadow(0 0 4px rgba(255,255,255,0.45)); }
  100% { filter: drop-shadow(0 0 0px rgba(156, 39, 176, 0.0)); }
`;

const sweep = keyframes`
  0% { transform: translateX(-120%); }
  100% { transform: translateX(120%); }
`;

const Profile = () => {
  const { user } = useAuth();
  const theme = useTheme();
  const [isEditing, setIsEditing] = useState(false);

  const getRoleColor = (role) => {
    switch (role) {
      // For custom gold styles we return default color and style via sx
      case 'superadmin':
        return 'default';
      case 'admin':
        return 'error';
      case 'assessor':
        return 'default';
      case 'verifier':
        return 'primary';
      case 'editor':
        return 'secondary';
      case 'viewer':
        return 'info';
      default:
        return 'default';
    }
  };

  const getRoleChipProps = (role) => {
    // Default props
    const base = { color: getRoleColor(role), sx: {}, icon: null };
    if (role === 'superadmin' || role === 'assessor') {
      return {
        ...base,
        color: 'default',
        icon: <AdminIcon />,
        sx: {
          backgroundImage: 'linear-gradient(135deg, #E1BEE7 0%, #CE93D8 40%, #BA68C8 70%, #9C27B0 100%)',
          animation: `${glow} 2.2s ease-in-out infinite`,
          color: '#fff',
          fontWeight: 700,
          boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.6)',
          position: 'relative',
          overflow: 'hidden',
          willChange: 'filter',
          '&::before': {
            content: '""',
            position: 'absolute',
            top: '-20%',
            left: '-50%',
            width: '200%',
            height: '140%',
            background: 'linear-gradient(120deg, rgba(255,255,255,0.0) 45%, rgba(255,255,255,0.45) 50%, rgba(255,255,255,0.0) 55%)',
            animation: `${sweep} 2.4s ease-in-out infinite`,
            pointerEvents: 'none',
          },
          '& .MuiChip-icon': { color: '#fff' },
        },
      };
    }
    if (role === 'admin') {
      return { 
        ...base, 
        color: getRoleColor(role), 
        icon: <AdminIcon />,
        sx: {
          position: 'relative',
          overflow: 'hidden',
          // Glass gradient overlay
          '&::after': {
            content: '""',
            position: 'absolute',
            inset: 0,
            background: 'linear-gradient(145deg, rgba(255,255,255,0.16) 0%, rgba(255,255,255,0.06) 35%, rgba(255,255,255,0.0) 60%)',
            pointerEvents: 'none',
          },
          // Moving highlight sweep
          '&::before': {
            content: '""',
            position: 'absolute',
            top: '-20%',
            left: '-50%',
            width: '200%',
            height: '140%',
            background: 'linear-gradient(120deg, rgba(255,255,255,0.0) 45%, rgba(255,255,255,0.35) 50%, rgba(255,255,255,0.0) 55%)',
            animation: `${sweep} 2.8s ease-in-out infinite`,
            pointerEvents: 'none',
          },
          '& .MuiChip-icon': { color: 'inherit' },
        }
      };
    }
    if (role === 'editor') {
      return { ...base, icon: <SecurityIcon /> };
    }
    if (role === 'verifier' || role === 'viewer') {
      return { ...base, icon: <SecurityIcon /> };
    }
    return base;
  };

  const getRoleDisplayName = (role) => {
    const roleNames = {
      'superadmin': 'Super Administrator',
      'admin': 'Administrator',
      'assessor': 'Municipal Assessor',
      'verifier': 'Verifier',
      'editor': 'Editor',
      'viewer': 'View Only'
    };
    return roleNames[role] || role;
  };

  const formatRole = (role) => {
    return getRoleDisplayName(role);
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
                      bgcolor: user?.avatar_url ? '#fff' : 'primary.main',
                      fontSize: '2rem'
                    }}
                  >
                    {!user?.avatar_url && (user?.full_name?.charAt(0) || user?.username?.charAt(0) || 'U')}
                  </Avatar>
                  <Box sx={{ flex: 1 }}>
                    <Typography variant="h5" fontWeight={600} gutterBottom>
                      {user?.full_name || user?.username || 'User'}
                    </Typography>
                    {(() => {
                      const chip = getRoleChipProps(user?.role);
                      return (
                        <Chip
                          label={getRoleDisplayName(user?.role)}
                          size="small"
                          color={chip.color}
                          icon={chip.icon}
                          sx={{ mt: 1, ...chip.sx }}
                        />
                      );
                    })()}
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
                      {formatAppDate(user.created_at)}
                    </Typography>
                  </Box>
                )}

                {user?.updated_at && (
                  <Box>
                    <Typography variant="caption" color="text.secondary" display="block" gutterBottom>
                      Last Updated
                    </Typography>
                    <Typography variant="body2">
                      {formatAppDate(user.updated_at)}
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

